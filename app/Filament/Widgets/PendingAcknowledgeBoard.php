<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\DocumentResource;
use App\Support\AcknowledgeStats;
use Filament\Widgets\Widget;

class PendingAcknowledgeBoard extends Widget
{
    protected static string $view = 'filament.widgets.pending-acknowledge-board';

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = ['default' => 'full', 'lg' => 2];

    protected function getViewData(): array
    {
        $stats = AcknowledgeStats::get();
        $pending = $stats->pending();

        return [
            'strips' => $pending->take(6)->map(fn (array $document) => [
                ...$document,
                'sections' => $stats->sectionPrefixes($document['sections']),
                'status' => match (true) {
                    $document['rate'] < 0.5 => 'low',
                    $document['rate'] < 0.8 => 'mid',
                    default => 'high',
                },
                'url' => DocumentResource::getUrl('follow', ['record' => $document['id']]),
            ]),
            'remaining' => max(0, $pending->count() - 6),
            'allUrl' => DocumentResource::getUrl('index'),
        ];
    }
}
