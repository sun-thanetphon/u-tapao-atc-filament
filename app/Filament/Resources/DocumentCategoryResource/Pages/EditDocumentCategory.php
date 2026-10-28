<?php

namespace App\Filament\Resources\DocumentCategoryResource\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\DocumentCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDocumentCategory extends EditRecord
{
    protected static string $resource = DocumentCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}