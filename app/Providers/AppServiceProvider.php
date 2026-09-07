<?php

namespace App\Providers;

use App\Models\AdminUser;
use App\Support\CurrentWorkspace;
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
        // One tenant context per request. Singleton because the global scope,
        // the policies and any queued job resolved in-request must all agree
        // on which workspace we are looking at.
        $this->app->singleton(CurrentWorkspace::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureStaffSuperAdmin();
    }

    /**
     * A missing permission must never lock the team out of their own console.
     *
     * Scoped strictly to AdminUser: returning null (not false) leaves every
     * other check to run normally, and a customer can never pick this up
     * because they are a different class on a different guard.
     */
    protected function configureStaffSuperAdmin(): void
    {
        Gate::before(fn ($user, string $ability) => $user instanceof AdminUser && $user->hasRole('super-admin')
            ? true
            : null);
    }

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
            : null
        );
    }
}
