<?php

namespace Tests\Feature;

use App\Filament\Widgets\ExamStudySetCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ExamStudySetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_home_lists_the_subjects_and_links_visible_documents(): void
    {
        $adc = $this->section('ADC');
        $annex = $this->makeDocument([$adc], [], ['name' => 'Annex 11 Air Traffic Services']);
        $hidden = $this->makeDocument([$this->section('APP')], [], ['name' => 'Annex 2 Rules of the Air']);

        config(['study_sets.exam.subjects' => [
            ['name' => 'Air Traffic Services (Annex 11)', 'documents' => [$annex->id]],
            ['name' => 'Rules of the Air (Annex 2)', 'documents' => [$hidden->id]],
            ['name' => 'AIP Thailand', 'url' => 'https://aip.caat.or.th/', 'url_label' => 'เปิดเว็บ AIP Thailand'],
        ]]);

        $this->actingAs($this->makeUser($adc));

        $this->get('/admin/home')
            ->assertOk()
            ->assertSee('ชุดอ่านสอบเลื่อนฐานะ ATC')
            ->assertSee('Air Traffic Services (Annex 11)')
            ->assertSee('Annex 11 Air Traffic Services')
            ->assertSee('https://aip.caat.or.th/', false);

        // เอกสารที่แผนกนี้ไม่เห็นไม่โผล่เป็นลิงก์ วิชานั้นขึ้นว่ายังไม่มีในระบบ
        Livewire::test(ExamStudySetCard::class)
            ->assertSee('Rules of the Air (Annex 2)')
            ->assertSee('ยังไม่มีในระบบ')
            ->assertDontSee('Annex 2 Rules of the Air');
    }
}
