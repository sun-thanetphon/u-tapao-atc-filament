<?php

namespace App\Filament\Widgets;

use App\Support\AcknowledgeStats;
use Filament\Widgets\Widget;

class SectionProgress extends Widget
{
    protected static string $view = 'filament.widgets.section-progress';

    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = ['default' => 'full', 'lg' => 1];

    protected function getViewData(): array
    {
        return [
            'sections' => AcknowledgeStats::get()->sections,
        ];
    }
}
