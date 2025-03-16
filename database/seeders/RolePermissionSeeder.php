<?php

namespace Database\Seeders;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        Role::create(['name' => RoleEnum::SUPERADMIN]);
        Role::create(['name' => RoleEnum::ADMIN]);
        Role::create(['name' => RoleEnum::USER]);

        Permission::create(['name' => PermissionEnum::VIEW]);
        Permission::create(['name' => PermissionEnum::ADD]);
        Permission::create(['name' => PermissionEnum::EDIT]);
        Permission::create(['name' => PermissionEnum::DELETE]);
    }
}