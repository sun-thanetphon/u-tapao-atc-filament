<?php

namespace App\Support;

use App\Enums\UserStatus;
use App\Models\Document;
use App\Models\DocumentAcknowledge;
use App\Models\Section;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * สถิติการรับทราบเอกสารสำหรับ Dashboard
 * นับเฉพาะการรับทราบของผู้ใช้ที่อยู่ในแผนกที่เอกสารกำหนดให้ต้องรับทราบ
 */
class AcknowledgeStats
{
    /** @var Collection<int, array{id: int, code: string, name: string, sections: array<int>, required: int, done: int, rate: float}> */
    public readonly Collection $documents;

    /** @var Collection<int, array{name: string, prefix: string, required: int, done: int, rate: ?float}> keyed by section id */
    public readonly Collection $sections;

    /**
     * คำนวณครั้งเดียวต่อ request แล้วใช้ร่วมกันทุก widget
     */
    public static function get(): self
    {
        if (! app()->bound(self::class)) {
            app()->instance(self::class, new self());
        }

        return app(self::class);
    }

    private function __construct()
    {
        $usersPerSection = User::query()
            ->active()
            ->selectRaw('section_id, count(*) as total')
            ->groupBy('section_id')
            ->pluck('total', 'section_id');

        $documents = Document::query()
            ->where('publish', true)
            ->latest()
            ->get(['id', 'code', 'name', 'acknowledge_sections'])
            ->filter(fn (Document $document) => filled($document->acknowledge_sections));

        // จำนวนการรับทราบ แยกตามเอกสารและแผนกของผู้รับทราบ
        $acknowledges = DocumentAcknowledge::query()
            ->join('users', 'users.id', '=', 'document_acknowledges.user_id')
            ->whereNull('users.deleted_at')
            ->where('users.status', UserStatus::ACTIVE)
            ->whereIn('document_acknowledges.document_id', $documents->pluck('id'))
            ->selectRaw('document_acknowledges.document_id, users.section_id, count(*) as total')
            ->groupBy('document_acknowledges.document_id', 'users.section_id')
            ->get()
            ->groupBy('document_id');

        $sectionTotals = [];

        $this->documents = $documents->map(function (Document $document) use ($usersPerSection, $acknowledges, &$sectionTotals) {
            $sections = array_map('intval', $document->acknowledge_sections);
            $doneBySection = ($acknowledges[$document->id] ?? collect())->pluck('total', 'section_id');

            $required = 0;
            $done = 0;

            foreach ($sections as $sectionId) {
                $sectionRequired = (int) ($usersPerSection[$sectionId] ?? 0);
                $sectionDone = min((int) ($doneBySection[$sectionId] ?? 0), $sectionRequired);

                $required += $sectionRequired;
                $done += $sectionDone;

                $sectionTotals[$sectionId]['required'] = ($sectionTotals[$sectionId]['required'] ?? 0) + $sectionRequired;
                $sectionTotals[$sectionId]['done'] = ($sectionTotals[$sectionId]['done'] ?? 0) + $sectionDone;
            }

            return [
                'id' => $document->id,
                'code' => $document->code,
                'name' => $document->name,
                'sections' => $sections,
                'required' => $required,
                'done' => $done,
                'rate' => $required > 0 ? $done / $required : 1.0,
            ];
        })->values();

        $this->sections = Section::query()
            ->orderBy('id')
            ->get(['id', 'name', 'prefix'])
            ->mapWithKeys(fn (Section $section) => [$section->id => [
                'name' => str_replace('-', ' ', $section->name),
                'prefix' => $section->prefix,
                'required' => $sectionTotals[$section->id]['required'] ?? 0,
                'done' => $sectionTotals[$section->id]['done'] ?? 0,
                'rate' => ($sectionTotals[$section->id]['required'] ?? 0) > 0
                    ? $sectionTotals[$section->id]['done'] / $sectionTotals[$section->id]['required']
                    : null,
            ]]);
    }

    public function required(): int
    {
        return $this->documents->sum('required');
    }

    public function done(): int
    {
        return $this->documents->sum('done');
    }

    public function rate(): ?float
    {
        return $this->required() > 0 ? $this->done() / $this->required() : null;
    }

    /**
     * เอกสารที่ยังมีคนไม่รับทราบ เรียงจากความคืบหน้าน้อยที่สุด
     */
    public function pending(): Collection
    {
        return $this->documents
            ->filter(fn (array $document) => $document['done'] < $document['required'])
            ->sortBy('rate')
            ->values();
    }

    /**
     * ป้ายกำกับแผนกจาก id เช่น [1, 2] => ['ADC', 'APP']
     */
    public function sectionPrefixes(array $sectionIds): array
    {
        return collect($sectionIds)->map(fn (int $id) => $this->sections[$id]['prefix'] ?? '?')->all();
    }
}
