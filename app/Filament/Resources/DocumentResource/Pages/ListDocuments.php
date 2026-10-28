<?php

namespace App\Filament\Resources\DocumentResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use App\Filament\Concerns\SearchesDocuments;
use App\Filament\Resources\DocumentResource;
use App\Enums\RoleEnum;
use App\Models\Document;
use App\Support\DocumentNotifier;
use App\Support\PendingDocumentsReminder;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListDocuments extends ListRecords
{
    use SearchesDocuments;

    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('remindAllPending')
                ->label('ส่งเมลเตือนรวมทุกเอกสารที่ค้าง')
                ->icon('heroicon-o-envelope')
                ->color('warning')
                ->visible(fn () => auth()->user()->hasRole(RoleEnum::SUPERADMIN))
                ->requiresConfirmation()
                ->modalHeading('ส่งอีเมลเตือนรวม')
                ->modalDescription(function () {
                    $reminder = app(PendingDocumentsReminder::class);
                    $plan = $reminder->plan();

                    $summary = 'จะส่งอีเมลเตือนผู้ใช้ ' . $reminder->userCount($plan) . ' คน แบ่งเป็น ' . count($plan['groups']) . ' กลุ่ม รวม '
                        . $reminder->messageCount($plan) . " ฉบับ ({$plan['without_email']} คนไม่มีอีเมล, {$plan['cooling']} คนเพิ่งถูกเตือนใน "
                        . PendingDocumentsReminder::COOLDOWN_HOURS . ' ชม.)';

                    $rows = collect($reminder->recipientList($plan))
                        ->map(fn (array $row) => '<li>' . e("{$row['name']} ({$row['section']}) ค้าง {$row['documents']} เอกสาร, ฉบับที่ {$row['message']}") . '</li>')
                        ->implode('');

                    return new HtmlString(e($summary) . ($rows !== '' ? '<ul style="margin-top:.75rem;text-align:left;list-style:disc;padding-left:1.25rem">' . $rows . '</ul>' : ''));
                })
                ->action(function () {
                    abort_unless(auth()->user()->hasRole(RoleEnum::SUPERADMIN), 403);

                    app(PendingDocumentsReminder::class)->send();
                }),
            // สร้าง/แก้ไขเอกสารเป็น modal บนหน้านี้ (ไม่มีหน้า create/edit แยก) จึงแจ้งอีเมลที่ hook นี้
            CreateAction::make()
                ->after(function (Document $record, $livewire): void {
                    app(DocumentNotifier::class)->notify($record, DocumentNotifier::wantedIn($livewire->getSchema($livewire->getMountedActionSchemaName())));
                }),
        ];
    }
}
