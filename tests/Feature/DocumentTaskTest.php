<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentTaskResource;
use App\Filament\Resources\DocumentTaskResource\Pages\ListDocumentTasks;
use App\Models\DocumentAcknowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_user_can_read_a_document_before_acknowledging_it(): void
    {
        $user = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);

        $this->actingAs($user);

        Livewire::test(ListDocumentTasks::class)
            ->assertCanSeeTableRecords([$document])
            ->assertTableActionVisible('viewPdf', $document)
            ->assertTableActionVisible('acknowledge', $document);
    }

    public function test_user_can_acknowledge_a_document_once(): void
    {
        $user = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);

        $this->actingAs($user);

        Livewire::test(ListDocumentTasks::class)
            ->callTableAction('acknowledge', $document)
            ->assertHasNoTableActionErrors()
            ->assertNotified('รับทราบเอกสารแล้ว');

        $this->assertDatabaseHas('document_acknowledges', [
            'user_id' => $user->id,
            'document_id' => $document->id,
        ]);

        // หลังรับทราบ ปุ่มรับทราบต้องหายไป แต่ยังอ่านได้ และมีบันทึกเดียว
        $document->refresh();

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('acknowledge', $document)
            ->assertTableActionVisible('viewPdf', $document);

        $this->assertSame(1, DocumentAcknowledge::where('user_id', $user->id)->count());
    }

    public function test_pending_tab_lists_only_documents_the_users_section_must_acknowledge(): void
    {
        $user = $this->makeUser($this->section('ADC'));
        $mustAcknowledge = $this->makeDocument([$this->section('ADC'), $this->section('APP')], [$this->section('ADC')]);
        $otherSectionMustAcknowledge = $this->makeDocument([$this->section('ADC'), $this->section('APP')], [$this->section('APP')]);
        $readOnly = $this->makeDocument([$this->section('ADC')]);
        $alreadyAcknowledged = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $alreadyAcknowledged->acknowledges()->create(['user_id' => $user->id, 'acknowledge_date' => now()]);

        $this->actingAs($user);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'pending'])
            ->assertCanSeeTableRecords([$mustAcknowledge])
            ->assertCanNotSeeTableRecords([$otherSectionMustAcknowledge, $readOnly, $alreadyAcknowledged]);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'acknowledged'])
            ->assertCanSeeTableRecords([$alreadyAcknowledged])
            ->assertCanNotSeeTableRecords([$mustAcknowledge, $otherSectionMustAcknowledge, $readOnly]);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$mustAcknowledge, $otherSectionMustAcknowledge, $readOnly, $alreadyAcknowledged])
            ->assertTableActionHidden('acknowledge', $otherSectionMustAcknowledge)
            ->assertTableActionHidden('acknowledge', $readOnly);

        $this->assertSame('1', DocumentTaskResource::getNavigationBadge());
    }

    public function test_user_does_not_see_documents_hidden_from_their_section_or_unpublished(): void
    {
        $user = $this->makeUser($this->section('ADC'));
        $otherSection = $this->makeDocument([$this->section('APP')], [$this->section('APP')]);
        $unpublished = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')], ['publish' => false]);

        $this->actingAs($user);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'all'])
            ->assertCanNotSeeTableRecords([$otherSection, $unpublished]);

        $this->assertNull(DocumentTaskResource::getNavigationBadge());
    }

    public function test_default_tab_is_pending_only_when_something_is_pending(): void
    {
        $user = $this->makeUser($this->section('ADC'));
        $this->actingAs($user);

        Livewire::test(ListDocumentTasks::class)->assertSet('activeTab', 'all');

        $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);

        Livewire::test(ListDocumentTasks::class)->assertSet('activeTab', 'pending');
    }

    public function test_sections_stored_as_integers_are_also_matched(): void
    {
        $user = $this->makeUser($this->section('ADC'));
        $document = $this->makeDocument([], [], [
            'view_sections' => [$this->section('ADC')],
            'acknowledge_sections' => [$this->section('ADC')],
        ]);

        $this->actingAs($user);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'pending'])
            ->assertCanSeeTableRecords([$document]);
    }
}
