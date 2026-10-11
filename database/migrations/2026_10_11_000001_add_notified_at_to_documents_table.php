<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เวลาที่ส่งอีเมลแจ้งเอกสารใหม่แล้ว (NULL = ยังไม่เคยส่ง) เอกสารเดิมทั้งหมดจึงยังเป็น NULL
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
