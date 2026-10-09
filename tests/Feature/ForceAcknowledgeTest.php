<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Filament\Resources\DocumentResource\Pages\FollowDocument;
use App\Filament\Resources\DocumentTaskResource;
use App\Filament\Resources\DocumentTaskResource\Pages\ListDocumentTasks;
use App\Support\AcknowledgeStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Super admin กด Force รับทราบแทนเจ้าหน้าที่ได้จากหน้าติดตามเอกสาร
 */
class ForceAcknowledgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_super_admin_can_force_acknowledge_for_a_user(): void
    {
        $superAdmin = $this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN);
        $member = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);

        $this->actingAs($superAdmin);

        Livewire::test(FollowDocument::class, ['record' => $document->id])
            ->assertCanSeeTableRecords([$member])
            ->assertTableActionVisible('supervisorAck', $member)
            ->callTableAction('supervisorAck', $member)
            ->assertNotified('Force acknowledge successfully');

        $this->assertDatabaseHas('document_acknowledges', [
            'user_id' => $member->id,
            'document_id' => $document->id,
        ]);

        // กดแล้วปุ่ม Force ของคนนั้นต้องหายไป
        Livewire::test(FollowDocument::class, ['record' => $document->id])
            ->assertTableActionHidden('supervisorAck', $member->fresh());
    }

    public function test_forced_acknowledgement_shows_up_for_the_user_and_on_the_dashboard(): void
    {
        $superAdmin = $this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN);
        $member = $this->makeUser($this->section('ADC'));
        $colleague = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);

        $this->actingAs($superAdmin);
        Livewire::test(FollowDocument::class, ['record' => $document->id])
            ->callTableAction('supervisorAck', $member);

        // ฝั่งเจ้าหน้าที่: เอกสารย้ายจาก "รอรับทราบ" ไป "รับทราบแล้ว" และไม่มีปุ่มรับทราบ
        $this->actingAs($member);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'pending'])
            ->assertCanNotSeeTableRecords([$document]);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'acknowledged'])
            ->assertCanSeeTableRecords([$document])
            ->assertTableActionHidden('acknowledge', $document)
            ->assertTableActionVisible('viewPdf', $document);

        $this->assertNull(DocumentTaskResource::getNavigationBadge());

        // เพื่อนร่วมแผนกที่ยังไม่ถูก force ยังต้องรับทราบเอง
        $this->actingAs($colleague);
        $this->assertSame('1', DocumentTaskResource::getNavigationBadge());

        // Dashboard นับการรับทราบที่ถูก force ด้วย
        $stats = AcknowledgeStats::get();
        $this->assertSame(2, $stats->required());
        $this->assertSame(1, $stats->done());
    }

    public function test_only_super_admin_sees_the_force_button(): void
    {
        $admin = $this->makeUser($this->section('APP'), RoleEnum::ADMIN);
        $member = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);

        $this->actingAs($admin);

        Livewire::test(FollowDocument::class, ['record' => $document->id])
            ->assertCanSeeTableRecords([$member])
            ->assertTableActionHidden('supervisorAck', $member);
    }

    public function test_regular_user_cannot_open_the_follow_page(): void
    {
        $member = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);

        $this->actingAs($member)
            ->get("/admin/documents/{$document->id}/follow")
            ->assertForbidden();
    }

    public function test_force_is_hidden_for_users_who_already_acknowledged(): void
    {
        $superAdmin = $this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN);
        $member = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);

        // เจ้าหน้าที่รับทราบเองผ่านหน้า "เอกสารของฉัน"
        $this->actingAs($member);
        Livewire::test(ListDocumentTasks::class)->callTableAction('acknowledge', $document);

        $this->actingAs($superAdmin);
        Livewire::test(FollowDocument::class, ['record' => $document->id])
            ->assertTableActionHidden('supervisorAck', $member->fresh());
    }
}
