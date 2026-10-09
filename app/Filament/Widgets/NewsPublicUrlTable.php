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

    protected function getImages(): array
    {
        return [
            asset('assets/home/IMG_A3D5B332BB77-1.jpeg'),
            asset('assets/home/IMG_0E0CBBC7CE7B-2.jpeg'),
        ];
    }
}
