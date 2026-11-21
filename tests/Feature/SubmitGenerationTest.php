<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\GenerationStateApplier;
use App\Services\Ledger;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test/v1');
    config()->set('pixelforge.api_key', 'pf_test_key');
});

/**
 * A pending generation with its hold placed, as the controller will leave it.
 *
 * @param  array<string, mixed>  $attributes
 */
function pendingGeneration(array $attributes = []): Generation
{
    $generation = Generation::factory()->create($attributes);
    app(Ledger::class)->hold($generation);

    return $generation;
}

/**
 * Run the job the way a worker would, with fake queue interactions so releases can be asserted.
 */
function runSubmit(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationStateApplier::class));

    return $job;
}

/**
 * @return array<string, mixed>
 */
function pixelforgeError(string $code, string $message = 'Nope.'): array
{
    return ['error' => ['type' => 'error', 'code' => $code, 'message' => $message]];
}

test('submits the generation with its idempotency key and metadata', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(remoteGeneration('queued', ['id' => 'gen_new']), 202)]);

    $generation = pendingGeneration(['prompt' => 'a red fox', 'size' => '1536x1024', 'quality' => 'high']);
    $job = runSubmit($generation);

    $job->assertNotReleased();
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_new')
        ->and($generation->submitted_at)->not->toBeNull();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->header('Authorization') === ['Bearer pf_test_key']
        && $request->header('Idempotency-Key') === [$generation->idempotency_key]
        && $request->data() === [
            'prompt' => 'a red fox',
            'size' => '1536x1024',
            'quality' => 'high',
            'metadata' => ['generation_id' => (string) $generation->id],
        ]);
});

test('sends reference images only when there are some', function () {
    Http::fake(['pixelforge.test/*' => Http::response(remoteGeneration('queued'), 202)]);

    runSubmit(pendingGeneration(['reference_images' => ['https://example.com/a.png']]));

    Http::assertSent(fn (Request $request) => $request['reference_images'] === ['https://example.com/a.png']);
});

test('an idempotent replay that has already finished is applied and charged once', function () {
    Http::fake(['pixelforge.test/*' => Http::response(remoteGeneration('succeeded'), 202, ['Idempotent-Replayed' => 'true'])]);

    $generation = pendingGeneration();
    runSubmit($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nanos)->toBe(211_350_000)
        ->and($generation->creditTransactions()->count())->toBe(3);
});

test('busy responses are retried after Retry-After and the generation stays pending', function (int $status, string $code, int $retryAfter) {
    Http::fake(['pixelforge.test/*' => Http::response(pixelforgeError($code), $status, ['Retry-After' => (string) $retryAfter])]);

    $generation = pendingGeneration();
    $job = runSubmit($generation);

    $job->assertReleased(delay: $retryAfter);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($generation->last_submission_error)->toBe($code)
        ->and($generation->user->balance_nanos)->toBe(-86_250_000); // still held, not charged
})->with([
    'concurrency limit' => [429, 'concurrency_limit_exceeded', 5],
    'rate limit' => [429, 'rate_limit_exceeded', 12],
    'overloaded' => [503, 'overloaded', 2],
]);

test('timeouts are retried with backoff, never treated as failed', function () {
    Http::fake(fn () => throw new ConnectionException('Operation timed out'));

    $generation = pendingGeneration();
    $job = runSubmit($generation);

    $job->assertReleased();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($generation->last_submission_error)->toBe('connection_error');
});

test('a bare 5xx is retried, because the generation may exist', function () {
    Http::fake(['pixelforge.test/*' => Http::response('Bad gateway', 502)]);

    $generation = pendingGeneration();
    runSubmit($generation)->assertReleased();

    expect($generation->refresh()->last_submission_error)->toBe('server_error');
});

test('definite rejections fail the generation for free', function (int $status, string $code, string $message) {
    Http::fake(['pixelforge.test/*' => Http::response(pixelforgeError($code, 'Pixelforge says no.'), $status)]);

    $generation = pendingGeneration();
    runSubmit($generation)->assertNotReleased();
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe($code)
        ->and($generation->error_message)->toBe($message)
        ->and($generation->charge_nanos)->toBe(0)
        ->and($generation->user->balance_nanos)->toBe(0);
})->with([
    'content policy' => [400, 'content_policy_violation', 'Pixelforge blocked this prompt under its content policy.'],
    'validation' => [422, 'validation_failed', 'Pixelforge says no.'],
    'daily limit' => [429, 'daily_limit_exceeded', "Pixelforge's daily generation limit has been reached. Please try again after midnight UTC."],
]);

test('waits without calling Pixelforge when our own records show it is full', function () {
    Http::fake();
    Generation::factory()->count(2)->submitted()->create();
    Generation::factory()->submitted()->create(['status' => GenerationStatus::Processing]);

    $generation = pendingGeneration();
    runSubmit($generation)->assertReleased(delay: 5);

    Http::assertNothingSent();
    expect($generation->refresh()->last_submission_error)->toBe('concurrency_limit_exceeded');
});

test('does nothing if the generation was already accepted, e.g. by an early webhook', function () {
    Http::fake();

    runSubmit(Generation::factory()->submitted()->create());

    Http::assertNothingSent();
});

test('giving up after only busy responses fails the generation for free', function () {
    $generation = pendingGeneration(['last_submission_error' => 'concurrency_limit_exceeded']);

    (new SubmitGeneration($generation))->failed(null);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('provider_busy')
        ->and($generation->user->balance_nanos)->toBe(0);
});

test('giving up after a timeout leaves the generation pending for reconciliation', function () {
    $generation = pendingGeneration(['last_submission_error' => 'connection_error']);

    (new SubmitGeneration($generation))->failed(null);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending)
        ->and($generation->user->balance_nanos)->toBe(-86_250_000);
});

test('the client fetches a generation by id', function () {
    Http::fake(['pixelforge.test/v1/generations/gen_abc' => Http::response(remoteGeneration('processing', ['id' => 'gen_abc']))]);

    expect(app(PixelforgeClient::class)->getGeneration('gen_abc')['status'])->toBe('processing');
});
