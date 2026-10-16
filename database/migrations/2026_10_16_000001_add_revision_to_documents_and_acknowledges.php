<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * revision นับรอบของไฟล์ PDF: เปลี่ยนไฟล์แล้วทุกคนต้องรับทราบใหม่ แต่ประวัติรอบเก่ายังอยู่
     * และ unique กันรับทราบซ้ำรอบเดียวกัน
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('file_path');
        });

        Schema::table('document_acknowledges', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1)->after('document_id');
        });

        // แถวซ้ำ (คนเดียวกัน เอกสารเดียวกัน) เก็บไว้เฉพาะแถวแรกสุด ก่อนใส่ unique
        $keep = DB::table('document_acknowledges')
            ->selectRaw('min(id) as id')
            ->groupBy('user_id', 'document_id', 'revision');

        DB::table('document_acknowledges')
            ->whereNotIn('id', DB::query()->fromSub($keep, 'keep')->select('id'))
            ->delete();

        Schema::table('document_acknowledges', function (Blueprint $table) {
            $table->unique(['user_id', 'document_id', 'revision'], 'document_acknowledges_user_document_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::table('document_acknowledges', function (Blueprint $table) {
            $table->dropUnique('document_acknowledges_user_document_revision_unique');
            $table->dropColumn('revision');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
};
