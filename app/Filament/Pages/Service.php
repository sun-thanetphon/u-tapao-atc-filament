<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Service extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static string $view = 'filament.pages.service';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationGroup = 'URL & Public';
}
