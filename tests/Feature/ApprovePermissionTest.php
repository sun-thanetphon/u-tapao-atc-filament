<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    public function test_admin_and_super_admin_can_approve_but_user_cannot(): void
    {
        $section = $this->section('ADC');

        $this->assertTrue($this->makeUser($section, RoleEnum::ADMIN)->can('user.approve'));
        $this->assertTrue($this->makeUser($section, RoleEnum::SUPERADMIN)->can('user.approve'));
        $this->assertFalse($this->makeUser($section, RoleEnum::USER)->can('user.approve'));
    }

    public function test_migration_is_idempotent_on_a_seeded_database(): void
    {
        $migration = require database_path('migrations/2026_10_10_000002_add_user_approve_permission.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, \Spatie\Permission\Models\Permission::where('name', 'user.approve')->count());
        $this->assertTrue($this->makeUser($this->section('ADC'), RoleEnum::ADMIN)->can('user.approve'));
    }
}
