<?php

namespace App\Filament\Resources\TutorialVideoResource\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\TutorialVideoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTutorialVideos extends ListRecords
{
    protected static string $resource = TutorialVideoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
