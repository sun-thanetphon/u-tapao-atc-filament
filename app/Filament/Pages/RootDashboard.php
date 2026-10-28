<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard;

/**
 * หน้า /admin ยังเปิดได้เหมือนเดิม แต่ไม่ขึ้นเป็นเมนู Dashboard ซ้ำ (เมนู Dashboard คือ CustomDashboard ที่ /admin/dashboard)
 */
class RootDashboard extends Dashboard
{
    protected static bool $shouldRegisterNavigation = false;
}
