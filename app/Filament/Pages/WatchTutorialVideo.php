<?php

namespace App\Filament\Pages;

use Filament\Panel;
use App\Enums\RoleEnum;
use App\Models\TutorialVideo;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * หน้าเล่นวิดีโอทีละคลิป (เปิดจากรายการที่ TutorialVideos) ไม่อยู่ในเมนู
 */
class WatchTutorialVideo extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.watch-tutorial-video';

    public TutorialVideo $video;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'learning-video/{video}';
    }

    public function mount(TutorialVideo $video): void
    {
        $canSeeDrafts = auth()->user()->hasAnyRole([RoleEnum::SUPERADMIN, RoleEnum::ADMIN]);

        abort_unless($video->publish || $canSeeDrafts, 404);

        $this->video = $video;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->video->title;
    }

    public function getBreadcrumbs(): array
    {
        return [url('/admin/learning-video') => 'Learning Video'];
    }
}
