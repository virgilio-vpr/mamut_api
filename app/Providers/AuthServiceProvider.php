<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::define('view-users', fn (User $user): bool => $user->hasAnyRole(['planner', 'system_admin']));
        Gate::define('manage-users', fn (User $user): bool => $user->hasRole('system_admin'));
        Gate::define('view-reports', fn (User $user): bool => $user->hasAnyRole(['planner', 'manager', 'system_admin']));
        Gate::define('triage-work-orders', fn (User $user): bool => $user->hasAnyRole(['aux_admin', 'planner', 'system_admin']));
        Gate::define('close-work-orders', fn (User $user): bool => $user->hasAnyRole(['aux_admin', 'planner', 'system_admin', 'maintainer']));
        Gate::define('manage-preventive-plans', fn (User $user): bool => $user->hasAnyRole(['planner', 'system_admin']));
    }
}
