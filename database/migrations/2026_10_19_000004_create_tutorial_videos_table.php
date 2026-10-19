<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // วิดีโอแนะนำการใช้ระบบ: อัปโหลดไฟล์เอง (upload) หรือฝังลิงก์ YouTube/Vimeo (embed)
        Schema::create('tutorial_videos', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('source', 8); // upload | embed
            $table->string('file_path')->nullable();
            $table->text('embed_url')->nullable();
            $table->unsignedInteger('seq')->default(1);
            $table->boolean('publish')->default(true);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tutorial_videos');
    }
};
