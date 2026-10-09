<?php

namespace App\Filament\Widgets;

use App\Enums\PublicUrlCategoryEnum;
use App\Models\PublicUrl;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * รายการลิงก์สาธารณะของหน้า Home (Duty Roster, เอกสารสำคัญ, ประชาสัมพันธ์)
 */
abstract class PublicUrlListWidget extends Widget
{
    protected static string $view = 'filament.widgets.public-url-list';

    protected static ?string $heading = null;

    protected static string $icon = 'heroicon-o-link';

    protected static int $limit = 8;

    protected int | string | array $columnSpan = 'full';

    abstract protected static function category(): PublicUrlCategoryEnum;

    public static function isDiscovered(): bool
    {
        return false;
    }

    protected function getViewData(): array
    {
        $links = PublicUrl::query()
            ->publish()
            ->where('category', static::category()->value)
            ->ordered()
            ->limit(static::$limit)
            ->get(['id', 'name', 'url']);

        return [
            'heading' => static::$heading,
            'icon' => static::$icon,
            'links' => $links,
        ];
    }
}
