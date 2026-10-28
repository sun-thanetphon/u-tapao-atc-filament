<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard;
use Filament\Panel;

class CustomDashboard extends Dashboard
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    // protected static string $view = 'filament.pages.custom-dashboard';

    protected static ?int $navigationSort = 1;

    protected static string $routePath = 'dashboard';

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 3];
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->pages([]);
    }
}
