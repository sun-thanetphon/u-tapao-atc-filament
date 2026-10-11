<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เวลาที่ส่งอีเมลแจ้งเอกสารใหม่แล้ว (NULL = ยังไม่เคยส่ง)
        if (! Schema::hasColumn('documents', 'notified_at')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->timestamp('notified_at')->nullable();
            });
        }

        // เอกสารที่เผยแพร่อยู่แล้วก่อนมีฟีเจอร์นี้ ถือว่าแจ้งแล้ว กันผู้ดูแลแก้เอกสารเก่าแล้วส่งอีเมลหาทุกคนโดยไม่ตั้งใจ
        // (เอกสารที่ยังไม่เผยแพร่ยังเป็น NULL จึงแจ้งได้ตอนเผยแพร่)
        DB::table('documents')
            ->whereNull('notified_at')
            ->where('publish', true)
            ->update(['notified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
