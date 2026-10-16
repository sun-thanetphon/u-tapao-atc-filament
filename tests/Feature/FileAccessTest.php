<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ไฟล์ใน storage เปิดได้เฉพาะ PDF ของเอกสารที่ผู้ใช้มีสิทธิ์เห็น
 */
class FileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        Storage::fake('local');
        Storage::disk('local')->put('documents/adc.pdf', '%PDF-adc');
        Storage::disk('local')->put('email-imports/emails.csv', 'username,email');
    }

    private function adcDocument(array $attributes = []): void
    {
        $this->makeDocument([$this->section('ADC')], [], ['file_path' => 'documents/adc.pdf', ...$attributes]);
    }

    public function test_user_in_a_viewing_section_can_open_the_document(): void
    {
        $this->adcDocument();
        $this->actingAs($this->makeUser($this->section('ADC')));

        $response = $this->get('/storage/documents/adc.pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            // ไม่มี Content-Length แล้ว CDN ของ Hostinger ส่ง PDF ค้างกลางทาง
            ->assertHeader('Content-Length', (string) strlen('%PDF-adc'));

        $this->assertSame('%PDF-adc', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_the_pdf_is_named_after_the_document_not_the_stored_file(): void
    {
        $this->adcDocument(['code' => 'D2610/0001', 'name' => 'WI-ATS.ATCD-008 วิธีปฏิบัติ: NOTAM / AIP "Supplement"?']);
        $this->actingAs($this->makeUser($this->section('ADC')));

        $disposition = $this->get('/storage/documents/adc.pdf')->assertOk()->headers->get('Content-Disposition');

        $this->assertStringStartsWith('inline;', $disposition);
        // ชื่อไทยอยู่ใน filename* (UTF-8) ส่วน filename เป็นชื่อสำรอง ASCII จากรหัสเอกสาร
        $this->assertStringContainsString('filename=D2610_0001.pdf', $disposition);
        $this->assertStringContainsString(
            "filename*=utf-8''" . rawurlencode('WI-ATS.ATCD-008 วิธีปฏิบัติ NOTAM AIP Supplement.pdf'),
            $disposition,
        );
        $this->assertStringNotContainsString('adc.pdf', $disposition);
    }

    public function test_download_name_is_clean_and_never_empty(): void
    {
        $long = new \App\Models\Document(['code' => 'D2610', 'name' => str_repeat('ก', 300)]);
        $this->assertSame(120 + 4, mb_strlen($long->downloadName()));

        $blank = new \App\Models\Document(['code' => 'D26100002', 'name' => ' /:*? ']);
        $this->assertSame('D26100002.pdf', $blank->downloadName());
    }

    public function test_user_in_another_section_cannot_open_the_document(): void
    {
        $this->adcDocument();
        $this->actingAs($this->makeUser($this->section('APP')));

        $this->get('/storage/documents/adc.pdf')->assertNotFound();
    }

    public function test_unpublished_document_is_only_for_document_managers(): void
    {
        $this->adcDocument(['publish' => false]);

        $this->actingAs($this->makeUser($this->section('ADC')));
        $this->get('/storage/documents/adc.pdf')->assertNotFound();

        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN));
        $this->get('/storage/documents/adc.pdf')->assertOk();
    }

    public function test_files_that_are_not_documents_cannot_be_opened(): void
    {
        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN));

        $this->get('/storage/email-imports/emails.csv')->assertNotFound();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->adcDocument();

        $this->get('/storage/documents/adc.pdf')->assertRedirect();
        $this->assertGuest();
    }
}
