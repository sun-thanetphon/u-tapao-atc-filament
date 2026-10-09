<?php

namespace App\Filament\Widgets;

use App\Models\Section;
use Filament\Widgets\ChartWidget;

class UserSectionCountChart extends ChartWidget
{
    protected static ?string $heading = 'จำนวนเอกสารแต่ละแผนก';

    protected static ?int $sort = 2;

    protected static string $color = 'warning';

    protected function getData(): array
    {
        $sectionCountUsers = Section::withCount('users')->get();
        return [
            'datasets' => [
                [
                    'label' => 'จำนวนผู้ใช้',
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