<?php

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Ledger;
use App\Services\Pixelforge\Pricing\Pricing;
use App\Services\Pixelforge\Pricing\PricingTableRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    private const int HISTORY_LIMIT = 50;

    public function index(Request $request, PricingTableRepository $pricing): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Generate', [
            // The browser recomputes the estimate live from the same table the server uses.
            'pricing' => fn () => [
                ...$pricing->current()->toArray(),
                'markup_basis_points' => config()->integer('pixelforge.markup_basis_points'),
            ],
            'generations' => fn () => GenerationResource::collection(
                $user->generations()->latest('id')->limit(self::HISTORY_LIMIT)->get(),
            )->resolve(),
        ]);
    }

    public function store(
        StoreGenerationRequest $request,
        PricingTableRepository $pricingTables,
        Pricing $pricing,
        Ledger $ledger,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $estimate = $pricing->estimate(
            $pricingTables->current(),
            $request->string('prompt')->value(),
            $request->size(),
            $request->quality(),
            count($request->referenceImages()),
        );

        $generation = DB::transaction(function () use ($request, $user, $estimate, $ledger) {
            // Lock the user's row so two submits at once can't both spend the same credits.
            $balance = User::query()->lockForUpdate()->findOrFail($user->id)->balance();

            // The worst case must be affordable: the real price is never higher than it.
            if ($balance->isLessThan($estimate->maxCharge)) {
                throw ValidationException::withMessages([
                    'balance' => "This could cost up to {$estimate->maxCharge->format()} and your balance is {$balance->format()}. Buy credits to continue.",
                ]);
            }

            $generation = Generation::create([
                'user_id' => $user->id,
                'idempotency_key' => (string) Str::uuid(),
                'status' => GenerationStatus::Pending,
                'prompt' => $request->string('prompt')->value(),
                'size' => $request->size(),
                'quality' => $request->quality(),
                'reference_images' => $request->referenceImages(),
                'estimated_min_cost_nanos' => $estimate->minProviderCost->nanos,
                'estimated_max_cost_nanos' => $estimate->maxProviderCost->nanos,
                'estimated_min_charge_nanos' => $estimate->minCharge->nanos,
                'estimated_max_charge_nanos' => $estimate->maxCharge->nanos,
            ]);

            $ledger->hold($generation);

            return $generation;
        });

        SubmitGeneration::dispatch($generation);

        return back();
    }
}
