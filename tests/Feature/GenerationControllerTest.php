<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\GenerationStateApplier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('pixelforge.base_url', 'https://pixelforge.test/v1');
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(config('pixelforge.pricing.fallback'))]);
    Queue::fake();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generationInput(array $overrides = []): array
{
    return array_replace([
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ], $overrides);
}

test('guests are sent to log in', function () {
    $this->get(route('generate'))->assertRedirect(route('login'));
    $this->post(route('generations.store'), generationInput())->assertRedirect(route('login'));
});

test('the page has pricing for the live estimate and only the user\'s own generations', function () {
    $user = User::factory()->create();
    $mine = Generation::factory()->for($user)->create();
    Generation::factory()->create(); // someone else's

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Generate')
            ->where('pricing.rates.output_tokens', '40.000000000')
            ->where('pricing.tokens.output.high.1536x1024', 6450)
            ->where('pricing.markup_basis_points', 2500)
            ->has('generations', 1)
            ->where('generations.0.id', $mine->id)
            ->where('generations.0.status', 'pending')
            ->where('generations.0.estimated_max_charge', '0.086250000')
            ->where('generations.0.charge', null));
});

test('a finished generation shows its actual charge and image', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->submitted('gen_test')->create();
    app(GenerationStateApplier::class)->apply($generation, remoteGeneration('succeeded', [
        'image_url_expires_at' => now()->addHour()->toIso8601String(),
    ]));

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.0.status', 'succeeded')
            ->where('generations.0.charge', '0.211350000')
            ->where('generations.0.image_expired', false)
            ->where('generations.0.usage.reasoning_tokens', 2950)
            ->where('generations.0.image_url', fn ($url) => str_contains($url, 'gen_test.png')));
});

test('an expired image is flagged', function () {
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->submitted('gen_test')->create();
    app(GenerationStateApplier::class)->apply($generation, remoteGeneration('succeeded', [
        'image_url_expires_at' => now()->subMinute()->toIso8601String(),
    ]));

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page->where('generations.0.image_expired', true));
});

test('submitting records the estimate, holds the worst case and queues the submission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('generate'))
        ->post(route('generations.store'), generationInput())
        ->assertRedirect(route('generate'));

    $generation = Generation::sole();

    // The docs' worked example: worst case $0.373875 from Pixelforge, $0.46734375 for the user.
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->user_id)->toBe($user->id)
        ->and($generation->estimated_max_cost_nanos)->toBe(373_875_000)
        ->and($generation->estimated_max_charge_nanos)->toBe(467_343_750)
        ->and($generation->estimated_min_cost_nanos)->toBe(286_225_000)
        ->and($generation->reference_images)->toBe(['https://example.com/a.png', 'https://example.com/b.png'])
        ->and($generation->idempotency_key)->toBeString()
        ->and($generation->creditTransactions()->sole())
        ->type->toBe(CreditTransactionType::Hold)
        ->amount_nanos->toBe(-467_343_750);

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
});

test('blank reference image rows are ignored', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), generationInput(['reference_images' => ['', '  ', 'https://example.com/a.png']]))
        ->assertSessionHasNoErrors();

    expect(Generation::sole()->reference_images)->toBe(['https://example.com/a.png']);
});

test('invalid input is rejected and nothing is created', function (array $input, string $field) {
    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), generationInput($input))
        ->assertSessionHasErrors($field);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'no prompt' => [['prompt' => ''], 'prompt'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'too many references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'not a url' => [['reference_images' => ['not a url']], 'reference_images.0'],
    'not http(s)' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
]);
