<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class Home extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static string $view = 'filament.pages.home';

    protected static ?int $navigationSort = -2;

    public function getHeading(): string
    {
        return 'สวัสดี, ' . auth()->user()->getFullName();
    }

    public function getSubheading(): string
    {
        $today = now()->locale('th');

        // วันที่แบบ พ.ศ. เช่น "วันเสาร์ที่ 10 ตุลาคม 2569"
        return 'วัน' . $today->translatedFormat('l') . 'ที่ ' . $today->translatedFormat('j F') . ' ' . ($today->year + 543);
    }
}
