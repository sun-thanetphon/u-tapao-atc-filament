<?php

namespace App\Filament\Widgets;

use App\Models\DocumentAcknowledge;
use Filament\Widgets\Widget;

class LatestAcknowledge extends Widget
{
    protected static string $view = 'filament.widgets.latest-acknowledge';

    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = ['default' => 'full', 'lg' => 1];

    protected function getViewData(): array
    {
        return [
            'items' => DocumentAcknowledge::query()
                ->with(['user.rank', 'user.section', 'document:id,code,name'])
                ->latest('acknowledge_date')
                ->limit(7)
                ->get()
                ->filter(fn (DocumentAcknowledge $acknowledge) => $acknowledge->user && $acknowledge->document),
        ];
    }
}
