<?php

namespace App\Filament\Widgets;

use App\Models\DocumentAcknowledge;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class AcknowledgeActivityChart extends ChartWidget
{
    protected static ?string $heading = 'การรับทราบรายวัน';

    protected static ?string $description = '30 วันล่าสุด';

    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected static string $color = 'primary';

    protected static ?string $maxHeight = '260px';

    protected int | string | array $columnSpan = ['default' => 'full', 'lg' => 2];

    protected function getData(): array
    {
        $start = now()->subDays(29)->startOfDay();

        $counts = DocumentAcknowledge::query()
            ->where('acknowledge_date', '>=', $start)
            ->selectRaw('DATE(acknowledge_date) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = collect(range(0, 29))->map(fn (int $offset) => $start->copy()->addDays($offset));

        return [
            'datasets' => [
                [
                    'label' => 'รับทราบ',
                    'data' => $days->map(fn (Carbon $day) => (int) ($counts[$day->toDateString()] ?? 0)),
                    'backgroundColor' => 'rgb(44, 76, 136)',
                    'borderColor' => 'rgb(44, 76, 136)',
                    'hoverBackgroundColor' => '#E8B400',
                    'borderRadius' => 4,
                    'maxBarThickness' => 18,
                ],
            ],
            'labels' => $days->map(fn (Carbon $day) => $day->locale('th')->translatedFormat('j M')),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => [
                    'grid' => ['display' => false],
                    'ticks' => ['maxRotation' => 0, 'autoSkipPadding' => 16],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }
}
