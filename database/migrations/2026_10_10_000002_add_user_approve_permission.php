<?php

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permission = Permission::findOrCreate(PermissionEnum::USER_APPROVE, 'web');

        // ให้เฉพาะ role ที่มีอยู่แล้ว (ฐานข้อมูลใหม่ที่ยังไม่ seed จะไม่มี role)
        Role::whereIn('name', [RoleEnum::ADMIN, RoleEnum::SUPERADMIN])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('name', PermissionEnum::USER_APPROVE)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
