<?php

namespace App\Filament\Resources\PublicUrlResource\Pages;

use App\Filament\Resources\PublicUrlResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPublicUrl extends EditRecord
{
    protected static string $resource = PublicUrlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
