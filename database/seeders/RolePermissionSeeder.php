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
        $roleAdmin =  Role::create(['name' => RoleEnum::ADMIN]);
        $roleUser = Role::create(['name' => RoleEnum::USER]);

        // สำหรับเมนู User
        Permission::create(['name' => PermissionEnum::USER_VIEW]);
        Permission::create(['name' => PermissionEnum::USER_ADD]);
        Permission::create(['name' => PermissionEnum::USER_EDIT]);
        Permission::create(['name' => PermissionEnum::USER_DELETE]);

        // สำหรับเมนู Document
        Permission::create(['name' => PermissionEnum::DOCUMENT_VIEW]);
        Permission::create(['name' => PermissionEnum::DOCUMENT_ADD]);
        Permission::create(['name' => PermissionEnum::DOCUMENT_EDIT]);
        Permission::create(['name' => PermissionEnum::DOCUMENT_DELETE]);

        // สำหรับเมนู Task
        Permission::create(['name' => PermissionEnum::TASK_VIEW]);
        Permission::create(['name' => PermissionEnum::TASK_ADD]);
        Permission::create(['name' => PermissionEnum::TASK_EDIT]);
        Permission::create(['name' => PermissionEnum::TASK_DELETE]);

        // สำหรับเมนู Category
        Permission::create(['name' => PermissionEnum::CATEGORY_VIEW]);
        Permission::create(['name' => PermissionEnum::CATEGORY_ADD]);
        Permission::create(['name' => PermissionEnum::CATEGORY_EDIT]);
        Permission::create(['name' => PermissionEnum::CATEGORY_DELETE]);

        // สำหรับเมนู Section
        Permission::create(['name' => PermissionEnum::SECTION_VIEW]);
        Permission::create(['name' => PermissionEnum::SECTION_ADD]);
        Permission::create(['name' => PermissionEnum::SECTION_EDIT]);
        Permission::create(['name' => PermissionEnum::SECTION_DELETE]);

        $roleAdmin->givePermissionTo([
            PermissionEnum::USER_VIEW,
            PermissionEnum::USER_ADD,
            PermissionEnum::USER_EDIT,
            PermissionEnum::USER_DELETE,

            PermissionEnum::DOCUMENT_VIEW,
            PermissionEnum::DOCUMENT_ADD,
            PermissionEnum::DOCUMENT_EDIT,
            PermissionEnum::DOCUMENT_DELETE,

            PermissionEnum::TASK_VIEW,
            PermissionEnum::TASK_ADD,
            PermissionEnum::TASK_EDIT,
            PermissionEnum::TASK_DELETE,

            PermissionEnum::CATEGORY_VIEW,
            PermissionEnum::CATEGORY_ADD,
            PermissionEnum::CATEGORY_EDIT,
            PermissionEnum::CATEGORY_DELETE,

            PermissionEnum::SECTION_VIEW,
            PermissionEnum::SECTION_ADD,
            PermissionEnum::SECTION_EDIT,
            PermissionEnum::SECTION_DELETE,
        ]);

        // มอบสิทธิ์ให้กับ user เฉพาะเมนู Task
        $roleUser->givePermissionTo([
            PermissionEnum::TASK_VIEW,
            PermissionEnum::TASK_ADD,
            PermissionEnum::TASK_EDIT,
            PermissionEnum::TASK_DELETE,
        ]);
    }
}