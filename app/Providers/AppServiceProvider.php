<?php

namespace App\Providers;

use App\Enums\RoleEnum;
use App\Http\Responses\CustomLoginResponse;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Filament\Http\Middleware\Authenticate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            LoginResponse::class,
            CustomLoginResponse::class
        );
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
