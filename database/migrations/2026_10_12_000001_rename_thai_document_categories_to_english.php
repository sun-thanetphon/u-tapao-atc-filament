<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** ชื่อเดิม (ไทย) => ชื่อใหม่ (อังกฤษ) ป้ายจะเป็นข้อความก่อนวงเล็บ */
    private const NAMES = [
        'เอกสารด้านนิรภัย' => 'AVSEC (Aviation Security)',
        'เอกสารอื่นๆ' => 'Others',
    ];

    public function up(): void
    {
        foreach (self::NAMES as $old => $new) {
            DB::table('document_categories')->where('name', $old)->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::NAMES as $old => $new) {
            DB::table('document_categories')->where('name', $new)->update(['name' => $old]);
        }
    }
};
