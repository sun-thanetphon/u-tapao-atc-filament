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

        $this->get('/storage/documents/adc.pdf')->assertOk()->assertSee('%PDF-adc', false);
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
