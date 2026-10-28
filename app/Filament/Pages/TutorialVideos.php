<?php

namespace App\Filament\Pages;

use Illuminate\Support\Collection;
use App\Enums\RoleEnum;
use App\Models\TutorialVideo;
use Filament\Pages\Page;

/**
 * หน้าดูวิดีโอแนะนำ: ผู้ใช้ที่ล็อกอินเห็นเฉพาะที่เผยแพร่ ผู้ดูแลเห็นทั้งหมด
 */
class TutorialVideos extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-play-circle';

    protected static string | \UnitEnum | null $navigationGroup = 'Tasks & Acknowledgements';

    protected static ?string $navigationLabel = 'Learning Video';

    protected static ?string $title = 'Learning Video';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'learning-video';

    protected string $view = 'filament.pages.tutorial-videos';

    /**
     * @return array{videos: Collection<int, TutorialVideo>}
     */
    protected function getViewData(): array
    {
        $query = TutorialVideo::query()->ordered();

        if (! auth()->user()->hasAnyRole([RoleEnum::SUPERADMIN, RoleEnum::ADMIN])) {
            $query->publish();
        }

        return ['videos' => $query->get()];
    }
}
