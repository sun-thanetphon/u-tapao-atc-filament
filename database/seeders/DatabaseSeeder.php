<?php

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
            RankSeeder::class,
            SectionSeeder::class,
            DocumentCategorySeeder::class,
            RolePermissionSeeder::class
        ]);

        $user =  User::create([
            'rank_id' => 6,
            'section_id' => 1,
            'firstname' => env('FIRSTNAME_INIT'),
            'lastname' => env('LASTNAME_INIT'),
            'username' => env('USERNAME_INIT'),
            'password' => bcrypt(env('PASSWORD_INIT')),
        ]);

        $superAdmin = Role::where('name', RoleEnum::SUPERADMIN)->first();
        $user->assignRole($superAdmin);
    }
}