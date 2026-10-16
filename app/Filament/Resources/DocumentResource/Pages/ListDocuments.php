<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Concerns\SearchesDocuments;
use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Support\DocumentNotifier;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDocuments extends ListRecords
{
    use SearchesDocuments;

    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // สร้าง/แก้ไขเอกสารเป็น modal บนหน้านี้ (ไม่มีหน้า create/edit แยก) จึงแจ้งอีเมลที่ hook นี้
            Actions\CreateAction::make()
                ->after(function (Document $record, $livewire): void {
                    app(DocumentNotifier::class)->notify($record, DocumentNotifier::wantedIn($livewire->getMountedActionForm()));
                }),
        ];
    }
}
