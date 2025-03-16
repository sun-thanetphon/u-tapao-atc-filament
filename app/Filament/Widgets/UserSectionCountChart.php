<?php

namespace App\Filament\Widgets;

use App\Models\Section;
use Filament\Widgets\ChartWidget;

class UserSectionCountChart extends ChartWidget
{
    protected static ?string $heading = 'จำนวนเอกสารแต่ละแผนก';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $sectionCountUsers = Section::withCount('users')->get();
        return [
            'datasets' => [
                [
                    'label' => 'Blog posts created',
                    'data' => $sectionCountUsers->pluck('users_count'),
                ],
            ],
            'labels' =>  $sectionCountUsers->pluck('prefix'),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}