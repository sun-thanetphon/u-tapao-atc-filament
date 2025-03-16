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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('document_categories')->nullOnDelete();
            $table->string('code')->unique();
            $table->json('view_sections')->comment('แผนกที่ต้องเห็นเอกสาร');
            $table->json('acknowledge_sections')->nullable()->comment('แผนกที่ต้องรับทราบเอกสาร');
            $table->string('name');
            $table->string('file_path');
            $table->boolean('publish');
            $table->foreignId('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};