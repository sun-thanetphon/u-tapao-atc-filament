<?php

namespace App\Filament\Resources\PasswordResetRequestResource\Pages;

use App\Filament\Resources\PasswordResetRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListPasswordResetRequests extends ListRecords
{
    protected static string $resource = PasswordResetRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
