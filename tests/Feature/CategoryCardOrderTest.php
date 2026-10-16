<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentTaskResource;
use App\Models\DocumentCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryCardOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_follow_the_agreed_order_with_unlisted_before_others(): void
    {
        $this->seedReferenceData();
        DocumentCategory::query()->delete();

        $names = [
            'Others', 'SMS (Safety Management System)', 'TCAR - Air Navigation Services', 'CAAT Rules (ระเบียบ)',
            'CAAT Aviation Regulation', 'CAAT Draft (ร่าง/รับฟังความเห็น)', 'CAAT Regulation (ข้อบังคับ)', 'AVSEC (Aviation Security)',
            'Airport Authority U-Tapao International Airport (x)', 'ICAO-Documents', 'ICAO-Annexes', 'WI (Work Instruction)',
            'FM (Form)', 'SD (Supporting Document)', 'QM (Quality Manual)', 'QP (Quality Procedure)',
        ];
        foreach ($names as $name) {
            DocumentCategory::create(['name' => $name]);
        }

        $sorted = DocumentTaskResource::sortedCategories()->pluck('name')->all();

        $this->assertSame([
            'QP (Quality Procedure)', 'QM (Quality Manual)', 'SD (Supporting Document)', 'FM (Form)', 'WI (Work Instruction)',
            'ICAO-Annexes', 'ICAO-Documents',
            'Airport Authority U-Tapao International Airport (x)', 'AVSEC (Aviation Security)',
            'CAAT Regulation (ข้อบังคับ)', 'CAAT Rules (ระเบียบ)', 'CAAT Draft (ร่าง/รับฟังความเห็น)', 'CAAT Aviation Regulation',
            'TCAR - Air Navigation Services',
            'SMS (Safety Management System)',
            'Others',
        ], $sorted);
    }
}
