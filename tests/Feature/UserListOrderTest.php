<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserListOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function userCreatedAt(string $role, string $createdAt): User
    {
        $user = $this->makeUser($this->section('ADC'), $role);
        $user->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $user;
    }

    public function test_super_admin_first_then_admins_then_users_each_oldest_first(): void
    {
        $user1 = $this->userCreatedAt(RoleEnum::USER, '2026-01-01 08:00:00');
        $admin2 = $this->userCreatedAt(RoleEnum::ADMIN, '2026-03-01 08:00:00');
        $user2 = $this->userCreatedAt(RoleEnum::USER, '2026-02-01 08:00:00');
        $super = $this->userCreatedAt(RoleEnum::SUPERADMIN, '2026-05-01 08:00:00');
        $admin1 = $this->userCreatedAt(RoleEnum::ADMIN, '2026-02-15 08:00:00');

        $this->actingAs($super);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$super, $admin1, $admin2, $user1, $user2], inOrder: true);
    }

    public function test_user_with_both_admin_and_user_roles_counts_as_admin(): void
    {
        $plain = $this->userCreatedAt(RoleEnum::USER, '2026-01-01 08:00:00');
        $both = $this->userCreatedAt(RoleEnum::USER, '2026-06-01 08:00:00');
        $both->assignRole(RoleEnum::ADMIN);
        $super = $this->userCreatedAt(RoleEnum::SUPERADMIN, '2026-07-01 08:00:00');

        $this->actingAs($super);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$super, $both, $plain], inOrder: true);
    }
}
