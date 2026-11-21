<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Ledger;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test/v1');
    config()->set('pixelforge.api_key', 'pf_test_key');
    config()->set('pixelforge.poll_after_seconds', 120);
    Queue::fake();
});

/**
 * A submitted generation whose last update was $secondsAgo seconds ago.
 */
function inFlight(string $providerId, int $secondsAgo, GenerationStatus $status = GenerationStatus::Processing): Generation
{
    $generation = Generation::factory()->submitted($providerId)->create(['status' => $status]);
    $generation->last_synced_at = now()->subSeconds($secondsAgo);
    $generation->save();
    app(Ledger::class)->hold($generation);

    return $generation;
}

test('a generation that has gone quiet is polled and completed, as a webhook would', function () {
    Http::fake(['pixelforge.test/v1/generations/gen_quiet' => Http::response(remoteGeneration('succeeded', ['id' => 'gen_quiet']))]);
    $generation = inFlight('gen_quiet', secondsAgo: 300);

    $this->artisan('generations:reconcile')
        ->expectsOutput('Polled 1, finished 1, resubmitted 0.')
        ->assertSuccessful();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->charge_nanos)->toBe(211_350_000);
});

test('recently updated generations are left alone', function () {
    Http::fake();
    inFlight('gen_fresh', secondsAgo: 30);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Http::assertNothingSent();
});

test('a still-running generation just records that it was checked', function () {
    Http::fake(['pixelforge.test/*' => Http::response(remoteGeneration('processing', ['id' => 'gen_slow']))]);
    $generation = inFlight('gen_slow', secondsAgo: 300);

    $this->artisan('generations:reconcile')->expectsOutput('Polled 1, finished 0, resubmitted 0.');

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing)
        ->and($generation->last_synced_at?->isAfter(now()->subSeconds(5)))->toBeTrue();
});

test('a rate limit stops the run instead of burning more requests', function () {
    Http::fake(['pixelforge.test/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down']], 429, ['Retry-After' => '20'])]);
    inFlight('gen_a', secondsAgo: 300);
    inFlight('gen_b', secondsAgo: 400);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Http::assertSentCount(1);
});

test('other errors skip that generation and carry on', function () {
    Http::fake([
        'pixelforge.test/v1/generations/gen_missing' => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation']], 404),
        'pixelforge.test/v1/generations/gen_ok' => Http::response(remoteGeneration('failed', ['id' => 'gen_ok'])),
    ]);
    inFlight('gen_missing', secondsAgo: 400);
    $ok = inFlight('gen_ok', secondsAgo: 300);

    $this->artisan('generations:reconcile')->expectsOutput('Polled 1, finished 1, resubmitted 0.');

    expect($ok->refresh()->status)->toBe(GenerationStatus::Failed);
});

test('polls at most --limit generations per run, oldest first', function () {
    Http::fake(fn ($request) => Http::response(remoteGeneration('processing', ['id' => basename($request->url())])));
    inFlight('gen_newer', secondsAgo: 200);
    $oldest = inFlight('gen_oldest', secondsAgo: 900);

    $this->artisan('generations:reconcile', ['--limit' => 1]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/gen_oldest'));
    expect($oldest->refresh()->last_synced_at?->isAfter(now()->subSeconds(5)))->toBeTrue();
});

test('stuck pending generations are resubmitted, fresh ones are not', function () {
    $stuck = Generation::factory()->create(['created_at' => now()->subMinutes(10)]);
    Generation::factory()->create(['created_at' => now()->subSeconds(10)]);

    $this->artisan('generations:reconcile')->expectsOutput('Polled 0, finished 0, resubmitted 1.');

    Queue::assertPushed(SubmitGeneration::class, 1);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($stuck));
});

test('resubmitting is a no-op while the generation already has a job waiting', function () {
    $stuck = Generation::factory()->create(['created_at' => now()->subMinutes(10)]);

    SubmitGeneration::dispatch($stuck);
    $this->artisan('generations:reconcile');

    Queue::assertPushed(SubmitGeneration::class, 1);
});

test('the reconcile command is scheduled every ten seconds', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'generations:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event->repeatSeconds)->toBe(10);
});
