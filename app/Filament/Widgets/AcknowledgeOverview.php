<?php

namespace App\Filament\Widgets;

use App\Models\Document;
use App\Models\User;
use App\Support\AcknowledgeStats;
use Filament\Widgets\Widget;

class AcknowledgeOverview extends Widget
{
    protected static string $view = 'filament.widgets.acknowledge-overview';

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $stats = AcknowledgeStats::get();

        return [
            'rate' => $stats->rate(),
            'done' => $stats->done(),
            'required' => $stats->required(),
            'published' => Document::query()->where('publish', true)->count(),
            'pending' => $stats->pending()->count(),
            'users' => User::query()->active()->count(),
        ];
    }
}
