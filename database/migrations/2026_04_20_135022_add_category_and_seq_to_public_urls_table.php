<?php

use App\Enums\PublicUrlCategoryEnum;
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
        Schema::table('public_urls', function (Blueprint $table) {
            $table->string('category')->nullable()->after('id');
            $table->unsignedInteger('seq')->nullable()->after('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('public_urls', function (Blueprint $table) {
            $table->dropColumn(['category', 'seq']);
        });
    }
};
