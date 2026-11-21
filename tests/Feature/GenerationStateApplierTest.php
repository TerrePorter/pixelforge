<?php

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Services\GenerationStateApplier;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;

function applier(): GenerationStateApplier
{
    return app(GenerationStateApplier::class);
}

/**
 * A submitted generation with its worst-case price held, like after a real submit.
 */
function heldGeneration(): Generation
{
    $generation = Generation::factory()->submitted('gen_test')->create();
    app(Ledger::class)->hold($generation);

    return $generation->refresh();
}

/**
 * @return array<string, int>
 */
function ledgerFor(Generation $generation): array
{
    return $generation->creditTransactions()
        ->orderBy('id')
        ->get()
        ->mapWithKeys(fn (CreditTransaction $t) => [$t->type->value => $t->amount_nanos])
        ->all();
}

test('holding a generation reserves its worst-case price', function () {
    $generation = heldGeneration();

    expect(ledgerFor($generation))->toBe(['hold' => -86_250_000])
        ->and($generation->user->balance_nanos)->toBe(-86_250_000);
});

test('a succeeded generation is charged its actual cost plus 25%', function () {
    $generation = applier()->apply(heldGeneration(), remoteGeneration('succeeded'));

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->cost_nanos)->toBe(169_080_000)
        ->and($generation->charge_nanos)->toBe(211_350_000)
        ->and($generation->input_text_tokens)->toBe(41)
        ->and($generation->output_tokens)->toBe(3300)
        ->and($generation->reasoning_tokens)->toBe(2950)
        ->and($generation->image_url)->toContain('gen_test.png')
        ->and($generation->image_url_expires_at?->toIso8601ZuluString())->toBe('2026-11-21T14:00:20Z')
        ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-20T14:00:20Z')
        ->and(ledgerFor($generation))->toBe([
            'hold' => -86_250_000,
            'hold_release' => 86_250_000,
            'charge' => -211_350_000,
        ])
        ->and($generation->user->refresh()->balance_nanos)->toBe(-211_350_000);
});

test('the charge uses the rates on the generation, not current pricing', function () {
    $remote = remoteGeneration('succeeded', ['rates' => [
        'input_text_tokens' => '10.00',
        'input_image_tokens' => '20.00',
        'output_tokens' => '80.00',
        'reasoning_tokens' => '25.00',
    ]]);

    $generation = applier()->apply(heldGeneration(), $remote);

    expect($generation->cost_nanos)->toBe(338_160_000)
        ->and($generation->rates['output_tokens'])->toBe('80.00');
});

test('a failed generation costs nothing and its hold is released', function () {
    $generation = applier()->apply(heldGeneration(), remoteGeneration('failed'));

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->cost_nanos)->toBe(0)
        ->and($generation->charge_nanos)->toBe(0)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->error_message)->toBe('The model could not produce an image.')
        ->and(ledgerFor($generation))->toBe(['hold' => -86_250_000, 'hold_release' => 86_250_000])
        ->and($generation->user->refresh()->balance_nanos)->toBe(0);
});

test('the same succeeded event applied twice is charged once', function () {
    $generation = heldGeneration();

    applier()->apply($generation, remoteGeneration('succeeded'));
    applier()->apply($generation, remoteGeneration('succeeded'));

    expect(CreditTransaction::where('type', CreditTransactionType::Charge)->count())->toBe(1)
        ->and($generation->user->refresh()->balance_nanos)->toBe(-211_350_000);
});

test('a late processing event never moves a succeeded generation back', function () {
    $generation = heldGeneration();

    applier()->apply($generation, remoteGeneration('succeeded'));
    $after = applier()->apply($generation, remoteGeneration('processing'));

    expect($after->status)->toBe(GenerationStatus::Succeeded)
        ->and($after->charge_nanos)->toBe(211_350_000);
});

test('a late queued event never moves a processing generation back', function () {
    $generation = heldGeneration();

    applier()->apply($generation, remoteGeneration('processing'));
    $after = applier()->apply($generation, remoteGeneration('queued'));

    expect($after->status)->toBe(GenerationStatus::Processing)
        ->and($after->started_at?->toIso8601ZuluString())->toBe('2026-11-20T14:00:05Z');
});

test('terminal states are final, whichever arrives first', function (string $first, string $second, GenerationStatus $expected) {
    $generation = heldGeneration();

    applier()->apply($generation, remoteGeneration($first));
    $after = applier()->apply($generation, remoteGeneration($second));

    expect($after->status)->toBe($expected)
        ->and($generation->creditTransactions()->count())->toBe($expected === GenerationStatus::Succeeded ? 3 : 2);
})->with([
    'succeeded then failed' => ['succeeded', 'failed', GenerationStatus::Succeeded],
    'failed then succeeded' => ['failed', 'succeeded', GenerationStatus::Failed],
]);

test('a succeeded event can arrive before any other, even before we stored the provider id', function () {
    $generation = Generation::factory()->create(); // still pending, no provider id yet
    app(Ledger::class)->hold($generation);

    $after = applier()->apply($generation, remoteGeneration('succeeded', ['id' => 'gen_early']));

    expect($after->status)->toBe(GenerationStatus::Succeeded)
        ->and($after->provider_id)->toBe('gen_early')
        ->and($after->submitted_at)->not->toBeNull()
        ->and($after->started_at)->not->toBeNull();
});

test('queued and processing record progress without touching money', function () {
    $generation = Generation::factory()->create();
    app(Ledger::class)->hold($generation);

    $queued = applier()->apply($generation, remoteGeneration('queued'));
    expect($queued->status)->toBe(GenerationStatus::Queued)
        ->and($queued->started_at)->toBeNull();

    $processing = applier()->apply($generation, remoteGeneration('processing'));
    expect($processing->status)->toBe(GenerationStatus::Processing)
        ->and($processing->started_at?->toIso8601ZuluString())->toBe('2026-11-20T14:00:05Z')
        ->and($processing->cost_nanos)->toBeNull()
        ->and(ledgerFor($processing))->toBe(['hold' => -86_250_000]);
});

test('an event for a different provider generation is refused', function () {
    $generation = heldGeneration(); // gen_test

    applier()->apply($generation, remoteGeneration('succeeded', ['id' => 'gen_other']));
})->throws(LogicException::class);

test('a succeeded generation without usage is refused and nothing is recorded', function () {
    $generation = heldGeneration();

    try {
        applier()->apply($generation, remoteGeneration('succeeded', ['usage' => null]));
    } catch (InvalidArgumentException) {
        // expected
    }

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued)
        ->and(ledgerFor($generation))->toBe(['hold' => -86_250_000]);
});

test('a generation that never reached Pixelforge can be failed for free', function () {
    $generation = Generation::factory()->create();
    app(Ledger::class)->hold($generation);

    $failed = applier()->failBeforeSubmission($generation, 'content_policy_violation', 'The prompt was blocked.');

    expect($failed->status)->toBe(GenerationStatus::Failed)
        ->and($failed->charge_nanos)->toBe(0)
        ->and($failed->error_code)->toBe('content_policy_violation')
        ->and($failed->user->refresh()->balance_nanos)->toBe(0);
});

test('failing before submission does nothing once Pixelforge has accepted the generation', function () {
    $generation = heldGeneration(); // queued

    $after = applier()->failBeforeSubmission($generation, 'overloaded', 'Gave up.');

    expect($after->status)->toBe(GenerationStatus::Queued)
        ->and(ledgerFor($after))->toBe(['hold' => -86_250_000]);
});

test('the database refuses a second charge for the same generation', function () {
    $generation = applier()->apply(heldGeneration(), remoteGeneration('succeeded'));

    CreditTransaction::create([
        'user_id' => $generation->user_id,
        'generation_id' => $generation->id,
        'type' => CreditTransactionType::Charge,
        'amount_nanos' => -1,
    ]);
})->throws(UniqueConstraintViolationException::class);

test('settling an unfinished generation is a bug', function () {
    app(Ledger::class)->settle(heldGeneration());
})->throws(LogicException::class);

test('purchases add credit', function () {
    $user = heldGeneration()->user;

    app(Ledger::class)->purchase($user, Money::fromDecimal('5.00'));

    expect($user->refresh()->balance_nanos)->toBe(5_000_000_000 - 86_250_000);
});
