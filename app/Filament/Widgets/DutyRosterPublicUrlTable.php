<?php

namespace App\Filament\Widgets;

use App\Enums\PublicUrlCategoryEnum;

class DutyRosterPublicUrlTable extends PublicUrlListWidget
{
    protected static ?string $heading = 'Duty Roster';

    protected static string $icon = 'heroicon-o-calendar-days';

    protected static function category(): PublicUrlCategoryEnum
    {
        return PublicUrlCategoryEnum::DUTY_ROSTER;
    }
}
