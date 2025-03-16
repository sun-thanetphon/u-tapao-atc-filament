<?php

namespace App\Providers;

use App\Enums\RoleEnum;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Filament\Http\Middleware\Authenticate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;

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
        config('app.env') !== 'local' && URL::forceScheme('https');

        //เชื่อม Route filament กับ route web
        Authenticate::redirectUsing(fn() => Filament::getCurrentPanel()->route('auth.login'));

        Gate::before(function ($user, $ability) {
            return $user->hasRole(RoleEnum::SUPERADMIN) ? true : null;
        });
    }
}