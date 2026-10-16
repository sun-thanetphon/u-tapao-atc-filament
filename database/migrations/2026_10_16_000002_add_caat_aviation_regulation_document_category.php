<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NAME = 'CAAT Aviation Regulation';

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
