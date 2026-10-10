<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Models\Rank;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_new_users_default_to_active(): void
    {
        $user = $this->makeUser($this->section('ADC'));

        $this->assertSame('active', $user->fresh()->status);
    }

    public function test_pending_user_with_role_cannot_access_panel(): void
    {
        $user = $this->makeUser($this->section('ADC'));
        $user->forceFill(['status' => 'pending'])->save();

        $this->assertFalse($user->fresh()->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_rejected_active_session_is_denied_on_next_request(): void
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::ADMIN);

        $this->actingAs($user)->get('/admin')->assertOk();

        $user->forceFill(['status' => 'rejected'])->save();

        $this->assertNotSame(200, $this->actingAs($user->fresh())->get('/admin')->getStatusCode());
    }

    public function test_status_and_approval_columns_are_not_mass_assignable(): void
    {
        $user = User::create([
            'rank_id' => Rank::query()->value('id'),
            'section_id' => $this->section('ADC'),
            'username' => 'mass_assign',
            'firstname' => 'a',
            'lastname' => 'b',
            'password' => bcrypt('password'),
            'status' => 'pending',
            'must_change_password' => true,
        ]);

        $user = $user->fresh();
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->must_change_password);
    }
}
