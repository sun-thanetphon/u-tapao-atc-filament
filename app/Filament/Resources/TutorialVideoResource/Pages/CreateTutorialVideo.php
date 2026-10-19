<?php

namespace App\Filament\Resources\TutorialVideoResource\Pages;

use App\Filament\Resources\TutorialVideoResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTutorialVideo extends CreateRecord
{
    protected static string $resource = TutorialVideoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
