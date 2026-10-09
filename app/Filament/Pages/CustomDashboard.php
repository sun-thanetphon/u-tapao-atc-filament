<?php

namespace App\Filament\Pages;

use Filament\Panel;

class CustomDashboard extends \Filament\Pages\Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    // protected static string $view = 'filament.pages.custom-dashboard';

    protected static ?int $navigationSort = 1;

    protected static string $routePath = 'dashboard';

    public function getColumns(): int | string | array
    {
        return ['default' => 1, 'lg' => 3];
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->pages([]);
    }
}
