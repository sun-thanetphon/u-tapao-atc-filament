<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Models\User;
use App\Providers\Filament\Auth\CustomLogin;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function userWithStatus(string $status): User
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $user->forceFill(['status' => $status])->save();

        return $user;
    }

    protected function attempt(User $user, string $password = 'password')
    {
        return Livewire::test(CustomLogin::class)
            ->fillForm(['username' => $user->username, 'password' => $password])
            ->call('authenticate');
    }

    public function test_pending_user_with_correct_password_sees_pending_message(): void
    {
        $this->attempt($this->userWithStatus(UserStatus::PENDING))
            ->assertHasFormErrors(['username'])
            ->assertSee('บัญชีของคุณรอการอนุมัติจากผู้ดูแลระบบ');

        $this->assertGuest();
    }

    public function test_rejected_user_with_correct_password_sees_rejected_message(): void
    {
        $this->attempt($this->userWithStatus(UserStatus::REJECTED))
            ->assertHasFormErrors(['username'])
            ->assertSee('บัญชีของคุณไม่ได้รับการอนุมัติ กรุณาติดต่อผู้ดูแลระบบ');

        $this->assertGuest();
    }

    public function test_wrong_password_for_pending_user_only_shows_generic_failure(): void
    {
        $this->attempt($this->userWithStatus(UserStatus::PENDING), 'wrong-password')
            ->assertHasFormErrors(['username'])
            ->assertSee(__('filament-panels::pages/auth/login.messages.failed'))
            ->assertDontSee('รอการอนุมัติ')
            ->assertDontSee('ไม่ได้รับการอนุมัติ');

        $this->assertGuest();
    }

    public function test_active_user_logs_in_as_before(): void
    {
        $user = $this->userWithStatus(UserStatus::ACTIVE);

        $this->attempt($user)->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }
}
