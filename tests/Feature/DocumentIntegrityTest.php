<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentTaskResource\Pages\ListDocumentTasks;
use App\Models\Document;
use App\Models\DocumentAcknowledge;
use App\Support\AcknowledgeStats;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ความสมบูรณ์ของเอกสารและหลักฐานการรับทราบ:
 * ลบแบบ soft delete ไม่ทำไฟล์หาย, รับทราบซ้ำไม่ได้, เปลี่ยนไฟล์แล้วต้องรับทราบใหม่, รหัสเอกสารไม่ซ้ำ
 */
class DocumentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Storage::fake('local');
    }

    /** สร้างเอกสารผ่าน observer จริง (makeDocument ปิด event ไว้) */
    private function createDocumentWithEvents(array $attributes = []): Document
    {
        $this->actingAs($this->makeUser($this->section('APP')));

        return Document::create([
            'name' => 'เอกสารทดสอบ',
            'view_sections' => [(string) $this->section('ADC')],
            'acknowledge_sections' => [(string) $this->section('ADC')],
            'category_id' => \App\Models\DocumentCategory::query()->value('id'),
            'file_path' => 'documents/a.pdf',
            'publish' => true,
            ...$attributes,
        ]);
    }

    public function test_soft_deleting_a_document_keeps_its_file_and_restore_brings_it_back(): void
    {
        Storage::disk('local')->put('documents/a.pdf', '%PDF-a');
        $document = $this->createDocumentWithEvents();

        $document->delete();

        Storage::disk('local')->assertExists('documents/a.pdf');

        $document->restore();

        $this->assertNull($document->fresh()->deleted_at);
        Storage::disk('local')->assertExists('documents/a.pdf');
    }

    public function test_force_deleting_a_document_removes_its_file(): void
    {
        Storage::disk('local')->put('documents/a.pdf', '%PDF-a');
        $document = $this->createDocumentWithEvents();

        $document->forceDelete();

        Storage::disk('local')->assertMissing('documents/a.pdf');
    }

    public function test_the_same_user_cannot_acknowledge_the_same_revision_twice(): void
    {
        $user = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $document->acknowledges()->create(['user_id' => $user->id, 'acknowledge_date' => now()]);

        $this->expectException(QueryException::class);

        $document->acknowledges()->create(['user_id' => $user->id, 'acknowledge_date' => now()]);
    }

    public function test_replacing_the_file_bumps_revision_and_requires_a_new_acknowledgement(): void
    {
        Storage::disk('local')->put('documents/a.pdf', '%PDF-a');
        Storage::disk('local')->put('documents/b.pdf', '%PDF-b');

        $member = $this->makeUser($this->section('ADC'));
        $document = $this->createDocumentWithEvents(['notified_at' => now()]);
        $document->acknowledges()->create(['user_id' => $member->id, 'acknowledge_date' => now()]);

        $this->assertSame(1, $document->fresh()->revision);

        $document->update(['file_path' => 'documents/b.pdf']);

        $document->refresh();
        $this->assertSame(2, $document->revision);
        $this->assertNull($document->notified_at, 'ไฟล์ใหม่ต้องส่งอีเมลแจ้งได้อีกครั้ง');
        Storage::disk('local')->assertMissing('documents/a.pdf');

        // รอบเก่ายังอยู่เป็นหลักฐาน แต่ผู้ใช้ต้องรับทราบรอบใหม่
        $this->assertSame(1, DocumentAcknowledge::where('document_id', $document->id)->count());

        $this->actingAs($member);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'pending'])
            ->assertCanSeeTableRecords([$document])
            ->callTableAction('acknowledge', $document)
            ->assertHasNoTableActionErrors();

        $this->assertSame(
            [1, 2],
            DocumentAcknowledge::where('document_id', $document->id)->orderBy('revision')->pluck('revision')->all(),
        );
    }

    public function test_dashboard_stats_count_only_acknowledgements_of_the_current_revision(): void
    {
        $member = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $document->acknowledges()->create(['user_id' => $member->id, 'acknowledge_date' => now()]);

        $this->assertSame(1, AcknowledgeStats::get()->done());

        $document->forceFill(['revision' => 2])->save();
        app()->forgetInstance(AcknowledgeStats::class);

        $this->assertSame(0, AcknowledgeStats::get()->done());
    }

    public function test_document_codes_continue_from_the_highest_code_even_after_a_permanent_delete(): void
    {
        $first = $this->createDocumentWithEvents();
        $second = $this->createDocumentWithEvents();
        $third = $this->createDocumentWithEvents();

        $prefix = 'D' . now()->format('ym');
        $this->assertSame($prefix . '0001', $first->code);
        $this->assertSame($prefix . '0002', $second->code);
        $this->assertSame($prefix . '0003', $third->code);

        // นับจำนวนแถวจะออกเลข 0003 ซ้ำกับฉบับที่เหลืออยู่
        $second->forceDelete();

        $this->assertSame($prefix . '0004', $this->createDocumentWithEvents()->code);
    }
}
