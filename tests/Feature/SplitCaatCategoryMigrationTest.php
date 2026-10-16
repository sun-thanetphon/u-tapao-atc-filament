<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Support\CaatDocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitCaatCategoryMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_move_to_their_type_and_others_stay(): void
    {
        $this->seedReferenceData();
        $parent = DocumentCategory::firstOrCreate(['name' => 'CAAT Aviation Regulation']);
        $other = DocumentCategory::create(['name' => 'Other Cat']);
        $section = $this->section('ADC');

        $make = fn (string $name, $category) => $this->makeDocument([$section], [], ['name' => $name, 'category_id' => $category->id]);
        $regulation = $make('ข้อบังคับของสำนักงาน ฉบับที่ 22', $parent);
        $draft = $make('ร่างข้อบังคับ ฉบับที่ 5', $parent);
        $unknown = $make('Cover-regulation-signed', $parent);
        $elsewhere = $make('ข้อบังคับในประเภทอื่น', $other);
        $trashed = $make('ประกาศที่ถูกลบ', $parent);
        $trashed->delete();

        (require database_path('migrations/2026_10_16_000003_split_caat_aviation_regulation_by_type.php'))->up();

        $typeId = fn (string $name) => DocumentCategory::where('name', $name)->value('id');
        $this->assertSame($typeId(CaatDocumentType::REGULATION), $regulation->fresh()->category_id);
        $this->assertSame($typeId(CaatDocumentType::DRAFT), $draft->fresh()->category_id);
        $this->assertSame($parent->id, $unknown->fresh()->category_id);
        $this->assertSame($other->id, $elsewhere->fresh()->category_id);
        $this->assertSame($parent->id, Document::withTrashed()->find($trashed->id)->category_id);
    }
}
