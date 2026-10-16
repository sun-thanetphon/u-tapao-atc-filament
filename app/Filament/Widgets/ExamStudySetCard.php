<?php

namespace App\Filament\Widgets;

use App\Enums\PermissionEnum;
use App\Filament\Resources\DocumentTaskResource;
use App\Models\Document;
use Filament\Widgets\Widget;

/**
 * ชุดอ่านสอบ (ตั้งค่าใน config/study_sets.php) ไว้บนหน้า Home: วิชาสอบแต่ละวิชาพร้อมลิงก์เปิดเอกสาร
 */
class ExamStudySetCard extends Widget
{
    protected static string $view = 'filament.widgets.exam-study-set-card';

    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    public static function isDiscovered(): bool
    {
        return false;
    }

    public static function canView(): bool
    {
        return auth()->user()->can(PermissionEnum::TASK_VIEW) && filled(config('study_sets.exam.subjects'));
    }

    protected function getViewData(): array
    {
        $set = config('study_sets.exam');
        $ids = collect($set['subjects'])->pluck('documents')->flatten()->filter()->unique()->all();

        // เฉพาะเอกสารที่แผนกของผู้ใช้เปิดได้ ลิงก์ที่ไปเจอ 404 จะไม่โผล่
        $documents = DocumentTaskResource::visibleQuery(Document::query())
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'file_path'])
            ->keyBy('id');

        $subjects = collect($set['subjects'])->map(function (array $subject) use ($documents) {
            $links = collect($subject['documents'] ?? [])
                ->map(fn (int $id) => $documents->get($id))
                ->filter()
                ->map(fn (Document $document) => [
                    'label' => $document->name,
                    'url' => DocumentTaskResource::pdfUrl($document),
                ]);

            if (filled($subject['url'] ?? null)) {
                $links->push(['label' => $subject['url_label'] ?? $subject['url'], 'url' => $subject['url'], 'external' => true]);
            }

            return ['name' => $subject['name'], 'links' => $links->values()];
        });

        return [
            'title' => $set['title'],
            'description' => $set['description'],
            'subjects' => $subjects,
        ];
    }
}
