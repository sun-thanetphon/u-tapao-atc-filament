<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Exports\FollowExport;
use App\Filament\Widgets\MyDocumentsCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * การ์ด "เอกสารของฉัน" บน Dashboard และไฟล์ Export รายชื่อผู้รับทราบ
 */
class MyDocumentsCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_card_counts_only_my_pending_documents(): void
    {
        $member = $this->makeUser($this->section('ADC'));
        $pending = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $done = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $other = $this->makeDocument([$this->section('APP')], [$this->section('APP')]);
        $done->acknowledges()->create(['user_id' => $member->id, 'acknowledge_date' => now()]);

        $this->actingAs($member);

        Livewire::test(MyDocumentsCard::class)
            ->assertSee($pending->code)
            ->assertDontSee($done->code)
            ->assertDontSee($other->code)
            ->assertSee('ฉบับที่รอให้คุณรับทราบ');
    }

    public function test_card_says_all_done_when_nothing_is_pending(): void
    {
        $this->actingAs($this->makeUser($this->section('ADC')));

        Livewire::test(MyDocumentsCard::class)->assertSee('คุณรับทราบเอกสารครบแล้ว');
    }

    public function test_print_report_is_super_admin_only_and_lists_acknowledged_users(): void
    {
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $member = $this->makeUser($this->section('ADC'));
        $document->acknowledges()->create(['user_id' => $member->id, 'acknowledge_date' => '2026-10-10 09:12:44']);

        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::ADMIN))
            ->get(route('follow.print', $document))
            ->assertForbidden();

        $this->actingAs($this->makeUser($this->section('APP'), RoleEnum::SUPERADMIN))
            ->get(route('follow.print', $document))
            ->assertOk()
            ->assertSee($document->name)
            ->assertSee('10-10-2026')
            ->assertDontSee('09:12');
    }

    public function test_export_lists_only_acknowledged_users_with_four_columns(): void
    {
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);
        $acknowledged = $this->makeUser($this->section('ADC'));
        $waiting = $this->makeUser($this->section('ADC'));
        $document->acknowledges()->create(['user_id' => $acknowledged->id, 'acknowledge_date' => '2026-10-10 09:12:44']);

        $html = (new FollowExport($document))->view()->render();

        $this->assertStringContainsString($document->name, $html);
        $this->assertStringContainsString($acknowledged->firstname, $html);
        $this->assertSame(1, substr_count($html, '<tr>') - 1);
        $this->assertSame(4, substr_count($html, '<th>'));
        $this->assertStringNotContainsString('ยังไม่รับทราบ', $html);
        $this->assertStringContainsString('10-10-2026', $html);
        $this->assertStringNotContainsString('09:12', $html);
    }
}
