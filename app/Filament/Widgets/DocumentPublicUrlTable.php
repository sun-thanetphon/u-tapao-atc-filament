<?php

namespace App\Filament\Widgets;

use App\Enums\PublicUrlCategoryEnum;

class DocumentPublicUrlTable extends PublicUrlListWidget
{
    protected static ?string $heading = 'เอกสารสำคัญ';

    protected static string $icon = 'heroicon-o-document-text';

    protected static function category(): PublicUrlCategoryEnum
    {
        return PublicUrlCategoryEnum::DOCUMENT;
    }
}
