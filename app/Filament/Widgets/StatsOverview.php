<?php

namespace App\Filament\Widgets;

use App\Models\Document;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $documentsCount = Document::count();
        $subfix =   $documentsCount > 1 ? " Files" : " File";

        return [
            Stat::make('เอกสารทั้งหมด', $documentsCount . $subfix),
            Stat::make('รับทราบแล้ว', 99),
            Stat::make('คิดเป็น', number_format(888, 2) . " %")
        ];
    }
}