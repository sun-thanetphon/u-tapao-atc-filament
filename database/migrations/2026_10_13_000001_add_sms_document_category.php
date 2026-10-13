<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** ป้ายเป็นข้อความก่อนวงเล็บ ชื่อเต็มเป็นข้อความในวงเล็บ */
    private const NAME = 'SMS (Safety Management System)';

    public function up(): void
    {
        if (DB::table('document_categories')->where('name', self::NAME)->exists()) {
            return;
        }

        DB::table('document_categories')->insert([
            'name' => self::NAME,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('document_categories')->where('name', self::NAME)->delete();
    }
};
