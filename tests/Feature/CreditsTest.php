<?php

use App\Enums\CreditTransactionType;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
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
 * High quality landscape with a 400-character prompt and two references: worst case
 * $0.373875 from Pixelforge, so $0.46734375 for the user.
 *
 * @return array<string, mixed>
 */
function expensiveGeneration(): array
{
    return [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ];
}

test('guests cannot see or buy credits', function () {
    $this->get(route('credits'))->assertRedirect(route('login'));
    $this->post(route('credits.store'), ['amount' => 5])->assertRedirect(route('login'));
});

test('new users start with no credits', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('credits.balance', '0.000000000')
            ->where('credits.reserved', '0.000000000'));
});

test('buying a pack adds exactly that many dollars', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('credits'))
        ->post(route('credits.store'), ['amount' => 5])
        ->assertRedirect(route('credits'))
        ->assertSessionHas('success', 'Added $5.00 in credits.');

    expect($user->refresh()->balance_nanos)->toBe(5_000_000_000)
        ->and(CreditTransaction::sole())
        ->type->toBe(CreditTransactionType::Purchase)
        ->amount_nanos->toBe(5_000_000_000)
        ->generation_id->toBeNull();
});

test('only the offered packs can be bought', function (mixed $amount) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('credits.store'), ['amount' => $amount])
        ->assertSessionHasErrors('amount');

    expect($user->refresh()->balance_nanos)->toBe(0);
})->with([3, -5, 0, 1_000_000, 'abc', null]);

test('the credits page lists the user\'s own history, newest first', function () {
    $user = User::factory()->withCredits('1.00')->create();
    User::factory()->withCredits('20.00')->create(); // someone else
    $this->actingAs($user)->post(route('credits.store'), ['amount' => 5]);

    $this->actingAs($user)
        ->get(route('credits'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Credits')
            ->where('packs', [1, 5, 20])
            ->has('transactions', 2)
            ->where('transactions.0.amount', '5.000000000')
            ->where('transactions.1.amount', '1.000000000')
            ->where('credits.balance', '6.000000000'));
});

test('generating is blocked when the worst case is more than the balance', function () {
    $user = User::factory()->withCredits('0.40')->create();

    $this->actingAs($user)
        ->post(route('generations.store'), expensiveGeneration())
        ->assertSessionHasErrors([
            'balance' => 'This could cost up to $0.4673 and your balance is $0.40. Buy credits to continue.',
        ]);

    expect(Generation::count())->toBe(0)
        ->and($user->refresh()->balance_nanos)->toBe(400_000_000);
    Queue::assertNothingPushed();
});

test('a balance of exactly the worst case is enough', function () {
    $user = User::factory()->withCredits('0.46734375')->create();

    $this->actingAs($user)
        ->post(route('generations.store'), expensiveGeneration())
        ->assertSessionHasNoErrors();

    expect($user->refresh()->balance_nanos)->toBe(0);
    Queue::assertPushed(SubmitGeneration::class);
});

test('running generations reserve credits, so the next one has to fit in what is left', function () {
    $user = User::factory()->withCredits('0.90')->create();

    $this->actingAs($user)->post(route('generations.store'), expensiveGeneration())->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('generations.store'), expensiveGeneration())->assertSessionHasErrors('balance');

    expect(Generation::count())->toBe(1);

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('credits.balance', '0.432656250')
            ->where('credits.reserved', '0.467343750'));
});

test('when a generation finishes the unused reservation comes back', function () {
    $user = User::factory()->withCredits('1.00')->create();
    $this->actingAs($user)->post(route('generations.store'), expensiveGeneration());

    $generation = Generation::sole();
    $generation->forceFill(['provider_id' => 'gen_test'])->save();
    app(GenerationStateApplier::class)->apply($generation, remoteGeneration('succeeded'));

    // $1.00 − $0.21135 actual charge (the docs' usage example plus 25%)
    expect($user->refresh()->balance_nanos)->toBe(788_650_000);

    $this->actingAs($user)
        ->get(route('generate'))
        ->assertInertia(fn (Assert $page) => $page->where('credits.reserved', '0.000000000'));
});

test('a failed generation gives the whole reservation back', function () {
    $user = User::factory()->withCredits('1.00')->create();
    $this->actingAs($user)->post(route('generations.store'), expensiveGeneration());

    $generation = Generation::sole();
    $generation->forceFill(['provider_id' => 'gen_test'])->save();
    app(GenerationStateApplier::class)->apply($generation, remoteGeneration('failed'));

    expect($user->refresh()->balance_nanos)->toBe(1_000_000_000);
});
