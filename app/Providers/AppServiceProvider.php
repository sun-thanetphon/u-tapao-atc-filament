<?php

namespace App\Providers;

use App\Enums\RoleEnum;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Gate::policy(User::class, UserPolicy::class);
        // Gate::policy(Role::class, RolePolicy::class);
        // Gate::policy(Document::class, DocumentPolicy::class);

        Gate::before(function ($user, $ability) {
            return $user->hasRole(RoleEnum::SUPERADMIN) ? true : null;
        });
    }
}