<?php

namespace App\Http\Middleware;

use App\Enums\GenerationStatus;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user?->only(['id', 'name', 'email', 'is_admin']),
            ],
            'credits' => fn () => $user === null ? null : $this->credits($user),
            'flash' => [
                'success' => $request->session()->get('success'),
            ],
        ];
    }

    /**
     * The balance already has the worst-case price of running generations taken out;
     * "reserved" says how much of that is only held, not spent.
     *
     * @return array{balance: string, reserved: string}
     */
    private function credits(User $user): array
    {
        $reserved = (int) $user->generations()
            ->whereIn('status', [GenerationStatus::Pending, GenerationStatus::Queued, GenerationStatus::Processing])
            ->sum('estimated_max_charge_nanos');

        return [
            'balance' => $user->fresh()?->balance()->toDecimal() ?? '0.000000000',
            'reserved' => Money::nanos($reserved)->toDecimal(),
        ];
    }
}
