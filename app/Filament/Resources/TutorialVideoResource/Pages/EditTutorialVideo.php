<?php

namespace App\Filament\Resources\TutorialVideoResource\Pages;

use App\Filament\Resources\TutorialVideoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTutorialVideo extends EditRecord
{
    protected static string $resource = TutorialVideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
