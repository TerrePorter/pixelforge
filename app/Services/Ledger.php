<?php

namespace App\Services;

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only code that moves credits.
 *
 * Every change is an inserted credit_transactions row plus an atomic update of the
 * user's cached balance, in the same database transaction. Callers that need several
 * entries to land together (e.g. release + charge) wrap them in their own transaction.
 */
class Ledger
{
    public function purchase(User $user, Money $amount): CreditTransaction
    {
        if (! $amount->isGreaterThan(Money::zero())) {
            throw new LogicException('A purchase must be for a positive amount.');
        }

        return $this->record($user->id, null, CreditTransactionType::Purchase, $amount, 'Bought credits');
    }

    /**
     * Reserve the worst-case price while the generation runs.
     */
    public function hold(Generation $generation): CreditTransaction
    {
        return $this->record(
            $generation->user_id,
            $generation->id,
            CreditTransactionType::Hold,
            Money::zero()->minus($generation->estimatedMaxCharge()),
            'Reserved while generating',
        );
    }

    /**
     * Give back the hold and, if the generation succeeded, charge its actual price.
     * Failed generations are free. Must be called once the generation is terminal.
     */
    public function settle(Generation $generation): void
    {
        if (! $generation->status->isTerminal()) {
            throw new LogicException("Generation {$generation->id} is not finished yet.");
        }

        DB::transaction(function () use ($generation) {
            $hold = $generation->creditTransactions()->where('type', CreditTransactionType::Hold)->first();

            if ($hold !== null) {
                $this->record(
                    $generation->user_id,
                    $generation->id,
                    CreditTransactionType::HoldRelease,
                    Money::nanos(-$hold->amount_nanos),
                    'Reservation released',
                );
            }

            if ($generation->status === GenerationStatus::Succeeded) {
                if ($generation->charge_nanos === null) {
                    throw new LogicException("Generation {$generation->id} succeeded without a recorded charge.");
                }

                $this->record(
                    $generation->user_id,
                    $generation->id,
                    CreditTransactionType::Charge,
                    Money::zero()->minus(Money::nanos($generation->charge_nanos)),
                    'Image generated',
                );
            }
        });
    }

    private function record(int $userId, ?int $generationId, CreditTransactionType $type, Money $amount, string $description): CreditTransaction
    {
        return DB::transaction(function () use ($userId, $generationId, $type, $amount, $description) {
            // The unique (generation_id, type) index makes a second hold, release or charge
            // for the same generation throw here, rolling the whole transaction back.
            $transaction = CreditTransaction::create([
                'user_id' => $userId,
                'generation_id' => $generationId,
                'type' => $type,
                'amount_nanos' => $amount->nanos,
                'description' => $description,
            ]);

            User::whereKey($userId)->increment('balance_nanos', $amount->nanos);

            return $transaction;
        });
    }
}
