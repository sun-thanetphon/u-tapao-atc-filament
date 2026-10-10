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
        // ไม่มี unique index (ตารางใช้ SoftDeletes) จึงตรวจ username ซ้ำก่อนเริ่มใช้ระบบสมัครสมาชิก
        $duplicates = DB::table('users')
            ->whereNull('deleted_at')
            ->select(DB::raw('LOWER(username) as name'), DB::raw('COUNT(*) as total'))
            ->groupBy(DB::raw('LOWER(username)'))
            ->havingRaw('COUNT(*) > 1')
            ->pluck('name')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Duplicate usernames found, resolve them before migrating: ' . implode(', ', $duplicates)
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->after('username');
            $table->string('status')->default('active')->index()->after('password');
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('rejected_reason')->nullable()->after('approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropIndex(['status']);
            $table->dropColumn(['email', 'status', 'approved_at', 'rejected_reason']);
        });
    }
};
