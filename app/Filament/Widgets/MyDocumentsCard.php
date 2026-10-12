<?php

namespace App\Filament\Widgets;

use App\Enums\PermissionEnum;
use App\Filament\Resources\DocumentTaskResource;
use App\Models\Document;
use Filament\Widgets\Widget;

/**
 * สรุปเอกสารของผู้ใช้ที่ล็อกอินอยู่ ไว้บนสุดของ Dashboard
 */
class MyDocumentsCard extends Widget
{
    private const LIST_LIMIT = 3;

    private const NEW_DAYS = 7;

    protected static string $view = 'filament.widgets.my-documents-card';

    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()->can(PermissionEnum::TASK_VIEW);
    }

    protected function getViewData(): array
    {
        $pending = DocumentTaskResource::pendingQuery(Document::query());
        $pendingUrl = DocumentTaskResource::getUrl('index', ['activeTab' => 'pending']);

        return [
            'pendingCount' => (clone $pending)->count(),
            'pending' => $pending->with('category:id,name')->latest()->limit(self::LIST_LIMIT)->get(['id', 'code', 'name', 'category_id', 'created_at']),
            'newCount' => DocumentTaskResource::visibleQuery(Document::query())
                ->where('created_at', '>=', now()->subDays(self::NEW_DAYS))
                ->count(),
            'newDays' => self::NEW_DAYS,
            'pendingUrl' => $pendingUrl,
            'allUrl' => DocumentTaskResource::getUrl('index'),
        ];
    }
}
