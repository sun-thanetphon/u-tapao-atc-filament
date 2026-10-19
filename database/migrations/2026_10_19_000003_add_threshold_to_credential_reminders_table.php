<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * เตือนหลายรอบต่อรายการ (เหลือ 90/60/30 วัน) จึงต้องแยกแถวตามเกณฑ์
     * แถวที่มีอยู่ก่อนหน้า (ถ้ามี) คือรอบ 90 วัน
     */
    public function up(): void
    {
        Schema::table('credential_reminders', function (Blueprint $table) {
            $table->unsignedSmallInteger('threshold')->default(90)->after('expires_on');
            // สร้างดัชนีใหม่ก่อน เพราะดัชนีเดิมถูก foreign key ของ user_id ใช้อยู่
            $table->unique(['user_id', 'item', 'expires_on', 'threshold'], 'credential_reminders_unique_per_threshold');
        });

        Schema::table('credential_reminders', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'item', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::table('credential_reminders', function (Blueprint $table) {
            $table->unique(['user_id', 'item', 'expires_on']);
        });

        Schema::table('credential_reminders', function (Blueprint $table) {
            $table->dropUnique('credential_reminders_unique_per_threshold');
            $table->dropColumn('threshold');
        });
    }
};
