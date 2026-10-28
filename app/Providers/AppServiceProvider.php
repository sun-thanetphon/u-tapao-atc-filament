<?php

namespace App\Providers;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use App\Enums\RoleEnum;
use App\Http\Responses\CustomLoginResponse;
use Illuminate\Support\ServiceProvider;
use App\Listeners\LoginLogSubscriber;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Filament\Http\Middleware\Authenticate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables\Table;

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

        // เชื่อม Route filament กับ route web
        Authenticate::redirectUsing(fn() => Filament::getCurrentOrDefaultPanel()->route('auth.login'));

        Event::subscribe(LoginLogSubscriber::class);

        Gate::before(function ($user, $ability) {
            return $user->hasRole(RoleEnum::SUPERADMIN) ? true : null;
        });

        // คงพฤติกรรมเดิมของ Filament 3: filter มีผลทันที, เลือกแสดงทั้งหมดได้, ไม่เรียงตาม id เพิ่ม, กล่องในฟอร์มกว้างเต็มแถว
        Table::configureUsing(fn (Table $table) => $table
            ->deferFilters(false)
            ->paginationPageOptions([5, 10, 25, 50, 'all'])
            ->defaultKeySort(false));
        Section::configureUsing(fn (Section $section) => $section->columnSpanFull());
        Grid::configureUsing(fn (Grid $grid) => $grid->columnSpanFull());
        Fieldset::configureUsing(fn (Fieldset $fieldset) => $fieldset->columnSpanFull());
    }
}