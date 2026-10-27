<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ไฟล์ทุกฉบับที่เคยอัปโหลดให้เอกสาร (หนึ่งแถวต่อไฟล์) เก็บไว้เป็นหลักฐานว่าแต่ละรอบรับทราบเนื้อหาอะไร
        Schema::create('document_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('file_path');
            $table->boolean('minor')->default(false); // แก้ไขเล็กน้อย: เปลี่ยนไฟล์โดยไม่ขึ้นรอบใหม่
            $table->string('note')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // เอกสารที่มีอยู่แล้วเก็บได้แค่ไฟล์ปัจจุบัน (ไฟล์ฉบับก่อนถูกลบไปตอนเปลี่ยนไฟล์) ใช้เวลาแก้ไขล่าสุดเป็นเวลาอัปโหลด
        DB::table('documents')->orderBy('id')->each(function ($document) {
            DB::table('document_files')->insert([
                'document_id' => $document->id,
                'revision' => $document->revision,
                'file_path' => $document->file_path,
                'minor' => false,
                'uploaded_by' => $document->creator_id,
                'created_at' => $document->updated_at,
                'updated_at' => $document->updated_at,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_files');
    }
};
