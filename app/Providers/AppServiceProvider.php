<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\Pricing\Pricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Pricing::class, fn () => new Pricing(
            markupBasisPoints: config()->integer('pixelforge.markup_basis_points'),
        ));

        $this->app->bind(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config()->string('pixelforge.base_url'),
            apiKey: config('pixelforge.api_key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Operators only: the admin report shows every user's spend and our costs.
        Gate::define('admin', fn (User $user): bool => $user->is_admin);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
