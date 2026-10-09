<?php

namespace App\Filament\Widgets;

use App\Enums\PublicUrlCategoryEnum;

class NewsPublicUrlTable extends PublicUrlListWidget
{
    protected static ?string $heading = 'ประชาสัมพันธ์';

    protected static string $icon = 'heroicon-o-megaphone';

    protected static function category(): PublicUrlCategoryEnum
    {
        return PublicUrlCategoryEnum::NEWS;
    }
}
