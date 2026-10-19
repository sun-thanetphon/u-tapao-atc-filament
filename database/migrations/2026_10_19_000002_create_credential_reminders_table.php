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
        // จำว่าเตือนวันหมดอายุของใบอนุญาต/ใบรับรองแพทย์รายการไหนไปแล้ว (วันหมดอายุใหม่ = เตือนรอบใหม่)
        Schema::create('credential_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item', 16); // rating | licence | medical | language
            $table->date('expires_on');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'item', 'expires_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credential_reminders');
    }
};
