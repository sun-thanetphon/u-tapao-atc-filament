<?php

namespace App\Filament\Resources\DocumentTaskResource\Pages;

use App\Filament\Resources\DocumentTaskResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDocumentTask extends EditRecord
{
    protected static string $resource = DocumentTaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Actions\DeleteAction::make(),
        ];
    }
}