<?php

namespace App\Filament\Resources\DocumentTaskResource\Pages;

use App\Filament\Resources\DocumentTaskResource;
use App\Models\Document;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDocumentTasks extends ListRecords
{
    protected static string $resource = DocumentTaskResource::class;

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'เอกสารของฉัน';
    }

    public function getSubheading(): ?string
    {
        $pending = DocumentTaskResource::pendingQuery(Document::query())->count();

        return $pending > 0
            ? "มีเอกสาร {$pending} ฉบับที่รอให้คุณรับทราบ อ่านแล้วกด \"รับทราบ\" ที่ท้ายรายการ"
            : 'คุณรับทราบเอกสารครบทุกฉบับแล้ว';
    }

    public function getTabs(): array
    {
        $pending = DocumentTaskResource::pendingQuery(Document::query())->count();

        return [
            'pending' => Tab::make('รอรับทราบ')
                ->icon('heroicon-m-clock')
                ->badge($pending ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => DocumentTaskResource::pendingQuery($query)),
            'acknowledged' => Tab::make('รับทราบแล้ว')
                ->icon('heroicon-m-check-circle')
                ->modifyQueryUsing(fn (Builder $query) => DocumentTaskResource::acknowledgedQuery($query)),
            'all' => Tab::make('ทั้งหมด'),
        ];
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return DocumentTaskResource::pendingQuery(Document::query())->exists() ? 'pending' : 'all';
    }
}
