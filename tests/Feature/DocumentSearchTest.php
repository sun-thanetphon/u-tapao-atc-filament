<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentTaskResource\Pages\ListDocumentTasks;
use App\Models\DocumentCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        $this->actingAs($this->makeUser($this->section('ADC')));
    }

    private function doc(string $name, array $attributes = [])
    {
        return $this->makeDocument([$this->section('ADC')], [], ['name' => $name, ...$attributes]);
    }

    public function test_words_match_in_any_order(): void
    {
        $match = $this->doc('TCAR PEL Part-ATCO amendment');
        $other = $this->doc('TCAR PEL Part-FCL amendment');

        Livewire::test(ListDocumentTasks::class)
            ->searchTable('amendment ATCO')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_hyphen_and_space_are_interchangeable(): void
    {
        $match = $this->doc('Rules Part-ATCO.OR');

        Livewire::test(ListDocumentTasks::class)
            ->searchTable('Part ATCO OR')
            ->assertCanSeeTableRecords([$match]);
    }

    public function test_arabic_and_thai_digits_find_each_other(): void
    {
        $thai = $this->doc('ข้อบังคับ ฉบับที่ ๗๔');
        $arabic = $this->doc('ข้อบังคับ ฉบับที่ 75');

        Livewire::test(ListDocumentTasks::class)
            ->searchTable('74')
            ->assertCanSeeTableRecords([$thai])
            ->assertCanNotSeeTableRecords([$arabic])
            ->searchTable('๗๕')
            ->assertCanSeeTableRecords([$arabic])
            ->assertCanNotSeeTableRecords([$thai]);
    }

    public function test_category_name_is_searched(): void
    {
        $category = DocumentCategory::create(['name' => 'CAAT Aviation Regulation']);
        $inCategory = $this->doc('ประกาศฉบับหนึ่ง', ['category_id' => $category->id]);
        $outside = $this->doc('ประกาศอีกฉบับ');

        Livewire::test(ListDocumentTasks::class)
            ->searchTable('aviation')
            ->assertCanSeeTableRecords([$inCategory])
            ->assertCanNotSeeTableRecords([$outside]);
    }

    public function test_like_wildcards_are_searched_literally(): void
    {
        $match = $this->doc('Doc 100%_done');
        $other = $this->doc('Doc 1000 done');

        Livewire::test(ListDocumentTasks::class)
            ->searchTable('100%_done')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }
}
