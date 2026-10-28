<?php

namespace App\Filament\Resources\PublicUrlResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\PublicUrlResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPublicUrls extends ListRecords
{
    protected static string $resource = PublicUrlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
