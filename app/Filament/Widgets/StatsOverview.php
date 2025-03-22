<?php

namespace App\Filament\Widgets;

use App\Models\Document;
use App\Models\DocumentAcknowledge;
use App\Models\User;
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
            Stat::make('รับทราบแล้ว', DocumentAcknowledge::count()),
            Stat::make('ผู้ใช้ทั้งหมด', User::count())
        ];
    }
}
