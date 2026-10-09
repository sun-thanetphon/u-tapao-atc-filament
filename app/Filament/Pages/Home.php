<?php

namespace App\Filament\Pages;

use Carbon\Carbon;
use Filament\Pages\Page;

class Home extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static string $view = 'filament.pages.home';

    protected static ?int $navigationSort = -2;

    public function getHeading(): string
    {
        // หัวหน้าแสดงอยู่ในส่วน hero ของ view แทน
        return '';
    }

    protected function getViewData(): array
    {
        $today = now()->locale('th');
        $validUntil = Carbon::create(2030, 1, 13)->locale('th');

        return [
            'greeting' => 'สวัสดี, ' . auth()->user()->getFullName(),
            'today' => 'วัน' . $today->translatedFormat('l') . 'ที่ ' . $this->thaiDate($today, 'j F'),
            'certificate' => [
                'title' => 'ใบรับรองบริการการเดินอากาศ',
                'subtitle' => 'Air Navigation Services Certificate',
                'number' => 'ATM-ATS 01',
                'issuer' => 'สำนักงานการบินพลเรือนแห่งประเทศไทย (กพท.)',
                'validUntil' => $this->thaiDate($validUntil, 'j M'),
                'remaining' => $today->isBefore($validUntil)
                    ? 'อีก ' . $today->diff($validUntil)->format('%y ปี %m เดือน')
                    : 'หมดอายุแล้ว',
                'isValid' => $today->isBefore($validUntil),
                'services' => ['Approach Control Service', 'Aerodrome Control Service'],
                'pages' => [
                    asset('assets/home/IMG_A3D5B332BB77-1.jpeg'),
                    asset('assets/home/IMG_0E0CBBC7CE7B-2.jpeg'),
                ],
            ],
        ];
    }

    /**
     * วันที่แบบ พ.ศ. เช่น "10 ตุลาคม 2569"
     */
    private function thaiDate(Carbon $date, string $format): string
    {
        return $date->translatedFormat($format) . ' ' . ($date->year + 543);
    }
}
