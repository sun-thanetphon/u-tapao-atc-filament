<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentTaskResource;
use App\Filament\Resources\DocumentTaskResource\Pages\ListDocumentTasks;
use App\Models\DocumentCategory;
use App\Support\CategoryStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Filament\Actions\Testing\TestAction;

/**
 * หน้าเอกสารของฉัน เลือกดูตามประเภทเอกสาร
 */
class DocumentCategoryBrowseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_badge_and_full_name_come_from_the_category_name(): void
    {
        $this->assertSame('WI', CategoryStyle::badge('WI (Work Instruction)'));
        $this->assertSame('Work Instruction', CategoryStyle::fullName('WI (Work Instruction)'));
        $this->assertSame('ICAO-Annexes', CategoryStyle::badge('ICAO-Annexes'));
        $this->assertNull(CategoryStyle::fullName('ICAO-Annexes'));
        $this->assertSame('Other', CategoryStyle::badge(null));
        $this->assertSame('A (x)', CategoryStyle::badge('A (x) (y)'));
        $this->assertSame('y', CategoryStyle::fullName('A (x) (y)'));
        $this->assertSame(9, CategoryStyle::tone(null));
        $this->assertCount(8, array_unique(array_map(fn (int $id) => CategoryStyle::tone($id), range(1, 8))));
    }

    public function test_category_filter_shows_only_that_category_and_cards_count_visible_documents(): void
    {
        $wi = DocumentCategory::create(['name' => 'WI (Work Instruction)']);
        $qp = DocumentCategory::create(['name' => 'QP (Quality Procedure)']);
        $adc = $this->section('ADC');
        $member = $this->makeUser($adc);

        $inWi = $this->makeDocument([$adc], [$adc], ['category_id' => $wi->id]);
        $inQp = $this->makeDocument([$adc], [], ['category_id' => $qp->id]);
        $none = $this->makeDocument([$adc], [], ['category_id' => null]);
        $hidden = $this->makeDocument([$this->section('APP')], [], ['category_id' => $wi->id]);

        $this->actingAs($member);

        $cards = collect(DocumentTaskResource::categoryCards())->keyBy('key');
        $this->assertSame(3, $cards[null]['total']);
        $this->assertSame(1, $cards[(string) $wi->id]['total']);
        $this->assertSame(1, $cards[(string) $wi->id]['pending']);
        $this->assertSame(0, $cards[(string) $qp->id]['pending']);
        $this->assertSame(1, $cards['none']['total']);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$inWi, $inQp, $none])
            ->set('category', (string) $wi->id)
            ->assertCanSeeTableRecords([$inWi])
            ->assertCanNotSeeTableRecords([$inQp, $none, $hidden])
            ->set('category', 'none')
            ->assertCanSeeTableRecords([$none])
            ->assertCanNotSeeTableRecords([$inWi, $inQp])
            ->set('category', '')
            ->assertCanSeeTableRecords([$inWi, $inQp, $none]);
    }

    public function test_unknown_or_invisible_category_falls_back_to_all(): void
    {
        $wi = DocumentCategory::create(['name' => 'WI (Work Instruction)']);
        $empty = DocumentCategory::create(['name' => 'FM (Form)']);
        $adc = $this->section('ADC');
        $inWi = $this->makeDocument([$adc], [], ['category_id' => $wi->id]);
        // ประเภทที่เอกสารอยู่ในแผนกอื่น: ผู้ใช้ ADC ไม่เห็น จึงไม่มีการ์ด
        $this->makeDocument([$this->section('APP')], [], ['category_id' => $empty->id]);

        $this->actingAs($this->makeUser($adc));

        foreach (['99999', (string) $empty->id, 'abc'] as $stale) {
            $component = Livewire::test(ListDocumentTasks::class, ['activeTab' => 'all'])
                ->set('category', $stale)
                ->assertCanSeeTableRecords([$inWi]);

            $this->assertNull($component->instance()->activeCategory());
        }
    }

    public function test_category_combines_with_the_status_tabs(): void
    {
        $wi = DocumentCategory::create(['name' => 'WI (Work Instruction)']);
        $qp = DocumentCategory::create(['name' => 'QP (Quality Procedure)']);
        $adc = $this->section('ADC');
        $member = $this->makeUser($adc);

        $pendingWi = $this->makeDocument([$adc], [$adc], ['category_id' => $wi->id]);
        $readOnlyWi = $this->makeDocument([$adc], [], ['category_id' => $wi->id]);
        $pendingQp = $this->makeDocument([$adc], [$adc], ['category_id' => $qp->id]);
        $doneWi = $this->makeDocument([$adc], [$adc], ['category_id' => $wi->id]);
        $doneWi->acknowledges()->create(['user_id' => $member->id, 'acknowledge_date' => now()]);

        $this->actingAs($member);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'pending'])
            ->set('category', (string) $wi->id)
            ->assertCanSeeTableRecords([$pendingWi])
            ->assertCanNotSeeTableRecords([$pendingQp, $readOnlyWi, $doneWi]);

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'acknowledged'])
            ->set('category', (string) $wi->id)
            ->assertCanSeeTableRecords([$doneWi])
            ->assertCanNotSeeTableRecords([$pendingWi, $pendingQp]);
    }

    public function test_pending_tab_badge_and_subheading_follow_the_selected_category(): void
    {
        $wi = DocumentCategory::create(['name' => 'WI (Work Instruction)']);
        $qp = DocumentCategory::create(['name' => 'QP (Quality Procedure)']);
        $adc = $this->section('ADC');
        $this->makeDocument([$adc], [$adc], ['category_id' => $wi->id]);
        $this->makeDocument([$adc], [$adc], ['category_id' => $qp->id]);
        $this->makeDocument([$adc], [$adc], ['category_id' => $qp->id]);

        $this->actingAs($this->makeUser($adc));

        $all = Livewire::test(ListDocumentTasks::class);
        $this->assertSame('3', $all->instance()->getCachedTabs()['pending']->getBadge());
        $this->assertStringContainsString('มีเอกสาร 3 ฉบับ', $all->instance()->getSubheading());

        // เลือกประเภทหลังหน้าโหลดแล้ว เหมือนการกดการ์ดจริง
        $qpOnly = Livewire::test(ListDocumentTasks::class)->set('category', (string) $qp->id);
        $this->assertSame('2', $qpOnly->instance()->getCachedTabs()['pending']->getBadge());
        $this->assertStringContainsString('ในประเภทนี้ 2 ฉบับ', $qpOnly->instance()->getSubheading());
    }

    public function test_cards_pending_count_updates_after_acknowledging(): void
    {
        $wi = DocumentCategory::create(['name' => 'WI (Work Instruction)']);
        $adc = $this->section('ADC');
        $document = $this->makeDocument([$adc], [$adc], ['category_id' => $wi->id]);

        $this->actingAs($this->makeUser($adc));

        $component = Livewire::test(ListDocumentTasks::class, ['activeTab' => 'pending']);
        $this->assertSame(1, collect($component->instance()->categoryCards())->firstWhere('key', (string) $wi->id)['pending']);

        $component->callAction(TestAction::make('acknowledge')->table($document));
        $this->assertSame(0, collect($component->instance()->categoryCards())->firstWhere('key', (string) $wi->id)['pending']);
    }

    public function test_old_category_select_filter_is_gone(): void
    {
        $this->actingAs($this->makeUser($this->section('ADC')));

        $component = Livewire::test(ListDocumentTasks::class)->assertSee('All');

        $this->assertArrayNotHasKey('category_id', $component->instance()->getTable()->getFilters());
    }

    public function test_documents_can_still_be_found_by_their_hidden_code(): void
    {
        $adc = $this->section('ADC');
        $document = $this->makeDocument([$adc], [$adc]);
        $other = $this->makeDocument([$adc], [$adc]);

        $this->actingAs($this->makeUser($adc));

        Livewire::test(ListDocumentTasks::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$document, $other])
            ->assertDontSee($document->code)
            ->searchTable($document->code)
            ->assertCanSeeTableRecords([$document])
            ->assertCanNotSeeTableRecords([$other]);
    }
}
