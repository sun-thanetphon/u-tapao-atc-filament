<?php

namespace App\Filament\Widgets;

use App\Models\DocumentAcknowledge;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsFollowOverview extends BaseWidget
{
    public string $documentId;
    public array $sections;

    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $countUsersInSections = User::whereIn('section_id', $this->sections)->count();
        $countAcknowledgeInThisDocId =  DocumentAcknowledge::where('document_id', $this->documentId)->count();
        $percent = $countAcknowledgeInThisDocId / $countUsersInSections * 100;
        return [
            Stat::make('ผู้ที่ต้องรับทราบทั้งหมด', $countUsersInSections),
            Stat::make('รับทราบแล้ว', $countAcknowledgeInThisDocId),
            Stat::make('คิดเป็น', number_format($percent, 2) . " %")
        ];
    }
}