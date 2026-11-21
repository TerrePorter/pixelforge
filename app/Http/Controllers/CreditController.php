<?php

namespace App\Http\Controllers;

use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\Ledger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    private const int HISTORY_LIMIT = 50;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Credits', [
            'packs' => $this->packs(),
            'transactions' => fn () => $user->creditTransactions()
                ->with('generation:id,prompt')
                ->latest('id')
                ->limit(self::HISTORY_LIMIT)
                ->get()
                ->map(fn (CreditTransaction $transaction) => [
                    'id' => $transaction->id,
                    'type' => $transaction->type->value,
                    'amount' => Money::nanos($transaction->amount_nanos)->toDecimal(),
                    'description' => $transaction->description,
                    'prompt' => $transaction->generation ? Str::limit($transaction->generation->prompt, 60) : null,
                    'created_at' => $transaction->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * A pretend purchase: no payment, the credits are simply added.
     */
    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', Rule::in($this->packs())],
        ]);

        /** @var User $user */
        $user = $request->user();
        $amount = Money::fromDecimal((string) $validated['amount']);

        $ledger->purchase($user, $amount);

        return back()->with('success', "Added {$amount->format()} in credits.");
    }

    /**
     * @return list<int>
     */
    private function packs(): array
    {
        /** @var list<int> $packs */
        $packs = config('pixelforge.credit_packs');

        return $packs;
    }
}
