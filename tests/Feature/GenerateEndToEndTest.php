<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Submits through the real (database) queue and a worker, with only HTTP faked.
 * This catches problems that Queue::fake() hides, such as the job failing to
 * serialize or to dispatch.
 */
test('a submitted generation goes through the real queue to Pixelforge', function () {
    config()->set('queue.default', 'database');
    config()->set('pixelforge.base_url', 'https://pixelforge.test/v1');
    config()->set('pixelforge.api_key', 'pf_test_key');

    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(config('pixelforge.pricing.fallback')),
        'pixelforge.test/v1/generations' => Http::response(remoteGeneration('queued', ['id' => 'gen_e2e']), 202),
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), [
            'prompt' => 'a lighthouse',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
        ])
        ->assertRedirect();

    expect(Generation::sole()->status)->toBe(GenerationStatus::Pending);

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(Generation::sole())
        ->status->toBe(GenerationStatus::Queued)
        ->provider_id->toBe('gen_e2e');
});
