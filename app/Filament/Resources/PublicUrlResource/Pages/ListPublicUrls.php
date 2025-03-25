<?php

namespace App\Filament\Resources\PublicUrlResource\Pages;

use App\Filament\Resources\PublicUrlResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPublicUrls extends ListRecords
{
    protected static string $resource = PublicUrlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
