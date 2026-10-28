<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * เก็บไฟล์ทุกฉบับของเอกสารเป็นหลักฐาน: เปลี่ยนไฟล์แล้วไฟล์เดิมยังอยู่ และผู้จัดการเอกสารเปิดฉบับเก่าได้
 */
class DocumentFileHistoryTest extends TestCase
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
        return Document::create([
            'name' => 'เอกสารทดสอบ',
            'view_sections' => [(string) $this->section('ADC')],
            'acknowledge_sections' => [(string) $this->section('ADC')],
            'category_id' => DocumentCategory::query()->value('id'),
            'file_path' => 'documents/a.pdf',
            'publish' => true,
            ...$attributes,
        ]);
    }

    public function test_creating_a_document_records_its_first_file(): void
    {
        $admin = $this->makeUser($this->section('APP'), RoleEnum::ADMIN);
        $this->actingAs($admin);

        $document = $this->createDocumentWithEvents();

        $file = DocumentFile::where('document_id', $document->id)->sole();
        $this->assertSame('documents/a.pdf', $file->file_path);
        $this->assertSame(1, $file->revision);
        $this->assertFalse($file->minor);
        $this->assertSame($admin->id, $file->uploaded_by);
    }

    public function test_replacing_the_file_keeps_the_old_file_and_records_the_new_revision(): void
    {
        Storage::disk('local')->put('documents/a.pdf', '%PDF-a');
        Storage::disk('local')->put('documents/b.pdf', '%PDF-b');
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));
        $document = $this->createDocumentWithEvents();

        $document->update(['file_path' => 'documents/b.pdf']);

        Storage::disk('local')->assertExists('documents/a.pdf');
        $this->assertSame(
            [[1, 'documents/a.pdf', false], [2, 'documents/b.pdf', false]],
            $document->files()->get()->map(fn (DocumentFile $file) => [$file->revision, $file->file_path, $file->minor])->all(),
        );
    }

    public function test_minor_change_keeps_the_old_file_and_records_it_as_minor(): void
    {
        Storage::disk('local')->put('documents/a.pdf', '%PDF-a');
        Storage::disk('local')->put('documents/b.pdf', '%PDF-b');
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));
        $document = $this->createDocumentWithEvents();

        // แบบเดียวกับที่ EditAction ตั้งค่าจากฟอร์มก่อนบันทึก
        $document->keepRevision = true;
        $document->update(['file_path' => 'documents/b.pdf']);

        Storage::disk('local')->assertExists('documents/a.pdf');
        $this->assertSame(
            [[1, 'documents/a.pdf', false], [1, 'documents/b.pdf', true]],
            $document->files()->get()->map(fn (DocumentFile $file) => [$file->revision, $file->file_path, $file->minor])->all(),
        );
    }

    public function test_edit_form_records_who_changed_the_file_and_the_note(): void
    {
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::ADMIN);
        $this->actingAs($admin);

        Livewire::test(ListDocuments::class)
            ->callTableAction('edit', $document, [
                'file_path' => UploadedFile::fake()->create('fixed.pdf', 10, 'application/pdf'),
                'require_reacknowledge' => 0,
                'revision_note' => 'แก้คำผิดหน้า 3',
            ])
            ->assertHasNoTableActionErrors();

        $file = $document->files()->sole();
        $this->assertSame($document->fresh()->file_path, $file->file_path);
        $this->assertSame(1, $file->revision);
        $this->assertTrue($file->minor);
        $this->assertSame('แก้คำผิดหน้า 3', $file->note);
        $this->assertSame($admin->id, $file->uploaded_by);
    }

    public function test_saving_without_a_new_file_adds_no_history(): void
    {
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));
        $document = $this->createDocumentWithEvents();

        $document->update(['name' => 'ชื่อใหม่']);

        $this->assertSame(1, $document->files()->count());
    }

    public function test_force_deleting_a_document_removes_every_stored_version(): void
    {
        Storage::disk('local')->put('documents/a.pdf', '%PDF-a');
        Storage::disk('local')->put('documents/b.pdf', '%PDF-b');
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));
        $document = $this->createDocumentWithEvents();
        $document->update(['file_path' => 'documents/b.pdf']);

        $document->forceDelete();

        Storage::disk('local')->assertMissing('documents/a.pdf');
        Storage::disk('local')->assertMissing('documents/b.pdf');
        $this->assertSame(0, DocumentFile::count());
    }

    public function test_migration_records_the_current_file_of_existing_documents(): void
    {
        $creator = $this->makeUser($this->section('APP'), RoleEnum::ADMIN);
        $current = $this->makeDocument([$this->section('ADC')], [], [
            'file_path' => 'documents/current.pdf',
            'creator_id' => $creator->id,
        ]);
        $current->forceFill(['revision' => 3])->saveQuietly();
        $trashed = $this->makeDocument([$this->section('ADC')], [], ['file_path' => 'documents/trashed.pdf']);
        $trashed->delete();

        $migration = require database_path('migrations/2026_10_28_000001_create_document_files_table.php');
        $migration->down();
        $migration->up();

        $file = DocumentFile::where('document_id', $current->id)->sole();
        $this->assertSame('documents/current.pdf', $file->file_path);
        $this->assertSame(3, $file->revision);
        $this->assertFalse($file->minor);
        $this->assertSame($creator->id, $file->uploaded_by);
        $this->assertSame('documents/trashed.pdf', DocumentFile::where('document_id', $trashed->id)->value('file_path'));
    }

    public function test_document_manager_can_open_an_old_version(): void
    {
        Storage::disk('local')->put('documents/a.pdf', '%PDF-a');
        Storage::disk('local')->put('documents/b.pdf', '%PDF-b');
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));
        $document = $this->createDocumentWithEvents(['name' => 'คู่มือ']);
        $document->update(['file_path' => 'documents/b.pdf']);
        $old = $document->files()->where('revision', 1)->sole();

        $response = $this->get(route('document.file', [$document, $old]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertSame('%PDF-a', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString(rawurlencode('คู่มือ (ฉบับ 1'), $response->headers->get('Content-Disposition'));
    }

    public function test_regular_user_cannot_open_an_old_version(): void
    {
        Storage::disk('local')->put('documents/a.pdf', '%PDF-a');
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));
        $document = $this->createDocumentWithEvents();
        $file = $document->files()->sole();

        $this->actingAs($this->makeUser($this->section('ADC')));

        $this->get(route('document.file', [$document, $file]))->assertNotFound();
    }

    public function test_a_file_of_another_document_is_not_found(): void
    {
        Storage::disk('local')->put('documents/a.pdf', '%PDF-a');
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));
        $first = $this->createDocumentWithEvents();
        $second = $this->createDocumentWithEvents();

        $this->get(route('document.file', [$second, $first->files()->sole()]))->assertNotFound();
    }

    public function test_history_lists_each_revision_with_its_files_and_acknowledgement_count(): void
    {
        Storage::disk('local')->put('documents/a.pdf', '%PDF-a');
        Storage::disk('local')->put('documents/b.pdf', '%PDF-b');
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));
        $document = $this->createDocumentWithEvents();
        $member = $this->makeUser($this->section('ADC'));
        $document->acknowledges()->create(['user_id' => $member->id, 'acknowledge_date' => now()]);
        $document->revisionNote = 'เพิ่มขั้นตอนฉุกเฉิน';
        $document->update(['file_path' => 'documents/b.pdf']);

        Livewire::test(ListDocuments::class)
            ->mountTableAction('history', $document)
            ->assertMountedActionModalSee(['ฉบับที่ 2', 'เพิ่มขั้นตอนฉุกเฉิน', 'ฉบับที่ 1', 'รับทราบ 1 คน']);
    }
}
