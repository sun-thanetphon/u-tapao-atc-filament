<?php

use App\Support\CaatDocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PARENT = 'CAAT Aviation Regulation';

    /**
     * แยกเอกสารใน "CAAT Aviation Regulation" ตามประเภทที่อ่านได้จากชื่อ
     * ที่จัดไม่ได้อยู่ประเภทเดิม ไม่แตะเอกสารที่ถูกลบ (soft delete) หรือเอกสารประเภทอื่น
     */
    public function up(): void
    {
        $parentId = DB::table('document_categories')->where('name', self::PARENT)->value('id');

        if (! $parentId) {
            return;
        }

        $ids = [];
        foreach (CaatDocumentType::all() as $name) {
            $ids[$name] = DB::table('document_categories')->where('name', $name)->value('id')
                ?? DB::table('document_categories')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('documents')
            ->where('category_id', $parentId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->each(function ($document) use ($ids) {
                $type = CaatDocumentType::classify($document->name);

                if ($type) {
                    DB::table('documents')->where('id', $document->id)->update(['category_id' => $ids[$type]]);
                }
            });
    }

    public function down(): void
    {
        $parentId = DB::table('document_categories')->where('name', self::PARENT)->value('id');
        $typeIds = DB::table('document_categories')->whereIn('name', CaatDocumentType::all())->pluck('id');

        if ($parentId) {
            DB::table('documents')->whereIn('category_id', $typeIds)->update(['category_id' => $parentId]);
        }

        DB::table('document_categories')->whereIn('id', $typeIds)->delete();
    }
};
