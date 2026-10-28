<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Filament\Resources\DocumentResource\Pages\FollowDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\LoginLogResource\Pages\ListLoginLogs;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ตารางต้องทำงานแบบเดิมหลังย้ายไป Filament 4: เลือก filter แล้วผลเปลี่ยนทันที และยังเลือกแสดงทั้งหมดได้
 */
class TableDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::SUPERADMIN));
    }

    public function test_filters_apply_immediately_and_all_rows_option_is_kept(): void
    {
        $document = $this->makeDocument([$this->section('ADC')], [$this->section('ADC')]);

        $pages = [
            [ListDocuments::class, []],
            [ListUsers::class, []],
            [ListLoginLogs::class, []],
            [FollowDocument::class, ['record' => $document->id]],
        ];

        foreach ($pages as [$page, $params]) {
            $table = Livewire::test($page, $params)->instance()->getTable();

            $this->assertFalse($table->hasDeferredFilters(), $page);
            $this->assertSame([5, 10, 25, 50, 'all'], $table->getPaginationPageOptions(), $page);
        }
    }
}
