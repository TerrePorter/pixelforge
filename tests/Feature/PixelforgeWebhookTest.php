<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Ledger;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Testing\TestResponse;

const TEST_WEBHOOK_SECRET = 'whsec_test_secret_123';

beforeEach(function () {
    config()->set('pixelforge.webhook_secret', TEST_WEBHOOK_SECRET);
});

/**
 * Build a webhook event body the way Pixelforge does.
 *
 * @param  array<string, mixed>  $object
 */
function webhookBody(string $eventId, string $type, array $object): string
{
    return (string) json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-20T14:00:20Z',
        'data' => ['object' => $object],
    ]);
}

function signatureFor(string $body, ?int $timestamp = null, string $secret = TEST_WEBHOOK_SECRET): string
{
    $timestamp ??= time();

    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}

/**
 * POST the exact raw body, as Pixelforge would.
 */
function deliverWebhook(string $body, ?string $signature = null, int $attempt = 1): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => (string) $attempt];

    if ($signature !== null) {
        $server['HTTP_PIXELFORGE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', route('webhooks.pixelforge'), [], [], [], $server, $body);
}

function deliverSigned(string $eventId, string $type, array $object): TestResponse
{
    $body = webhookBody($eventId, $type, $object);

    return deliverWebhook($body, signatureFor($body));
}

function submittedGeneration(): Generation
{
    $generation = Generation::factory()->submitted('gen_test')->create();
    app(Ledger::class)->hold($generation);

    return $generation;
}

test('a signed succeeded event completes the generation and charges it', function () {
    $generation = submittedGeneration();

    deliverSigned('evt_1', 'generation.succeeded', remoteGeneration('succeeded'))
        ->assertOk()
        ->assertJson(['received' => true]);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nanos)->toBe(211_350_000)
        ->and(WebhookEvent::where('event_id', 'evt_1')->first())
        ->processed_at->not->toBeNull()
        ->provider_generation_id->toBe('gen_test')
        ->delivery_attempt->toBe(1);
});

test('the whole whsec_ secret is the signing key', function () {
    submittedGeneration();
    $body = webhookBody('evt_1', 'generation.processing', remoteGeneration('processing'));
    $withoutPrefix = signatureFor($body, secret: 'test_secret_123');

    deliverWebhook($body, $withoutPrefix)->assertStatus(400);
});

test('requests without a valid signature are rejected and nothing is recorded', function (?string $signature) {
    $generation = submittedGeneration();
    $body = webhookBody('evt_1', 'generation.succeeded', remoteGeneration('succeeded'));

    deliverWebhook($body, $signature)->assertStatus(400);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and(WebhookEvent::count())->toBe(0);
})->with([
    'missing header' => [null],
    'empty header' => [''],
    'wrong signature' => ['t='.time().',v1='.str_repeat('a', 64)],
    'no timestamp' => ['v1='.str_repeat('a', 64)],
    'garbage' => ['nonsense'],
]);

test('a tampered body is rejected', function () {
    submittedGeneration();
    $body = webhookBody('evt_1', 'generation.failed', remoteGeneration('failed'));
    $signature = signatureFor($body);

    deliverWebhook(str_replace('generation_failed', 'internal_error', $body), $signature)->assertStatus(400);
});

test('timestamps more than five minutes away are rejected', function (int $offset, int $status) {
    submittedGeneration();
    $body = webhookBody('evt_1', 'generation.processing', remoteGeneration('processing'));

    deliverWebhook($body, signatureFor($body, time() + $offset))->assertStatus($status);
})->with([
    'just inside, past' => [-290, 200],
    'too old' => [-301, 400],
    'too far in the future' => [301, 400],
]);

test('the same event delivered twice is recorded and charged once', function () {
    $generation = submittedGeneration();
    $body = webhookBody('evt_dup', 'generation.succeeded', remoteGeneration('succeeded'));

    deliverWebhook($body, signatureFor($body), attempt: 1)->assertOk();
    deliverWebhook($body, signatureFor($body), attempt: 2)->assertOk()->assertJson(['duplicate' => true]);

    expect(WebhookEvent::count())->toBe(1)
        ->and(CreditTransaction::where('type', CreditTransactionType::Charge)->count())->toBe(1)
        ->and($generation->user->refresh()->balance_nanos)->toBe(-211_350_000);
});

test('two different events for the same success charge once', function () {
    $generation = submittedGeneration();

    deliverSigned('evt_a', 'generation.succeeded', remoteGeneration('succeeded'))->assertOk();
    deliverSigned('evt_b', 'generation.succeeded', remoteGeneration('succeeded'))->assertOk();

    expect(WebhookEvent::count())->toBe(2)
        ->and(CreditTransaction::where('type', CreditTransactionType::Charge)->count())->toBe(1)
        ->and($generation->user->refresh()->balance_nanos)->toBe(-211_350_000);
});

test('a processing event arriving after succeeded changes nothing', function () {
    $generation = submittedGeneration();

    deliverSigned('evt_2', 'generation.succeeded', remoteGeneration('succeeded'))->assertOk();
    deliverSigned('evt_1', 'generation.processing', remoteGeneration('processing'))->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
});

test('a failed event makes the generation free', function () {
    $generation = submittedGeneration();

    deliverSigned('evt_1', 'generation.failed', remoteGeneration('failed'))->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charge_nanos)->toBe(0)
        ->and($generation->user->refresh()->balance_nanos)->toBe(0);
});

test('a webhook that beats the create response is matched by our metadata id', function () {
    $generation = Generation::factory()->create(); // pending, no provider id yet
    app(Ledger::class)->hold($generation);

    deliverSigned('evt_1', 'generation.processing', remoteGeneration('processing', [
        'id' => 'gen_fast',
        'metadata' => ['generation_id' => (string) $generation->id],
    ]))->assertOk();

    expect($generation->refresh()->provider_id)->toBe('gen_fast')
        ->and($generation->status)->toBe(GenerationStatus::Processing);
});

test('metadata pointing at a row with a different provider id is not trusted', function () {
    $other = Generation::factory()->submitted('gen_other')->create();
    $generation = submittedGeneration(); // gen_test

    deliverSigned('evt_1', 'generation.processing', remoteGeneration('processing', [
        'metadata' => ['generation_id' => (string) $other->id],
    ]))->assertOk();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($other->refresh()->status)->toBe(GenerationStatus::Queued);
});

test('an event for an unknown generation is acknowledged', function () {
    deliverSigned('evt_1', 'generation.succeeded', remoteGeneration('succeeded', ['id' => 'gen_unknown']))
        ->assertOk();

    expect(WebhookEvent::first()?->processed_at)->not->toBeNull();
});

test('a ping from the dashboard is acknowledged', function () {
    $body = (string) json_encode(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-20T14:00:00Z', 'data' => []]);

    deliverWebhook($body, signatureFor($body))->assertOk();
});

test('a signed body that is not an event is rejected', function () {
    $body = '{"hello":"world"}';

    deliverWebhook($body, signatureFor($body))->assertStatus(400);
});

test('with no secret configured, every webhook is rejected', function () {
    config()->set('pixelforge.webhook_secret', null);
    submittedGeneration();
    $body = webhookBody('evt_1', 'generation.processing', remoteGeneration('processing'));

    deliverWebhook($body, signatureFor($body))->assertStatus(400);
});

test('the signature check accepts any of several v1 signatures', function () {
    $verifier = new WebhookSignature('whsec_x');
    $now = 1_800_000_000;
    $good = hash_hmac('sha256', "{$now}.body", 'whsec_x');

    expect($verifier->isValid('body', "t={$now},v1=deadbeef,v1={$good}", $now))->toBeTrue()
        ->and($verifier->isValid('body', "t={$now},v1=deadbeef", $now))->toBeFalse();
});
