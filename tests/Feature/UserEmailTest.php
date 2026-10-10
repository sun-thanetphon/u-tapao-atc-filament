<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Providers\Filament\Profile\ProfileEditCustom;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function withEmail(User $user, ?string $email): User
    {
        $user->forceFill(['email' => $email])->save();

        return $user;
    }

    private function editUser(User $admin, User $target, array $data)
    {
        return Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('edit', $target, $data);
    }

    public function test_user_adds_own_email_on_profile(): void
    {
        $user = $this->makeUser($this->section('ADC'));

        Livewire::actingAs($user)->test(ProfileEditCustom::class)
            ->fillForm(['email' => '  Me@Example.com '])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Me@Example.com', $user->fresh()->email);
    }

    public function test_profile_rejects_duplicate_email(): void
    {
        $this->withEmail($this->makeUser($this->section('ADC')), 'dup@example.com');
        $user = $this->makeUser($this->section('ADC'));

        Livewire::actingAs($user)->test(ProfileEditCustom::class)
            ->fillForm(['email' => 'dup@example.com'])
            ->call('save')
            ->assertHasFormErrors(['email']);

        $this->assertNull($user->fresh()->email);
    }

    public function test_profile_allows_email_of_soft_deleted_user(): void
    {
        $gone = $this->withEmail($this->makeUser($this->section('ADC')), 'reuse@example.com');
        $gone->delete();
        $user = $this->makeUser($this->section('ADC'));

        Livewire::actingAs($user)->test(ProfileEditCustom::class)
            ->fillForm(['email' => 'reuse@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('reuse@example.com', $user->fresh()->email);
    }

    public function test_profile_keeps_own_email_without_unique_error(): void
    {
        $user = $this->withEmail($this->makeUser($this->section('ADC')), 'own@example.com');

        Livewire::actingAs($user)->test(ProfileEditCustom::class)
            ->fillForm(['email' => 'own@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_profile_rejects_invalid_email(): void
    {
        $user = $this->makeUser($this->section('ADC'));

        Livewire::actingAs($user)->test(ProfileEditCustom::class)
            ->fillForm(['email' => 'not-an-email'])
            ->call('save')
            ->assertHasFormErrors(['email']);

        $this->assertNull($user->fresh()->email);
    }

    public function test_empty_email_is_stored_as_null_and_does_not_collide(): void
    {
        $other = $this->makeUser($this->section('ADC'));
        $user = $this->withEmail($this->makeUser($this->section('ADC')), 'x@example.com');

        Livewire::actingAs($user)->test(ProfileEditCustom::class)
            ->fillForm(['email' => '   '])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($user->fresh()->email);
        $this->assertNull($other->fresh()->email);
    }

    public function test_profile_password_change_still_works(): void
    {
        $user = $this->makeUser($this->section('ADC'));

        Livewire::actingAs($user)->test(ProfileEditCustom::class)
            ->fillForm(['password' => 'new-secret-123', 'passwordConfirmation' => 'new-secret-123'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-secret-123', $user->fresh()->password));
    }

    public function test_profile_without_password_keeps_old_password(): void
    {
        $user = $this->makeUser($this->section('ADC'));

        Livewire::actingAs($user)->test(ProfileEditCustom::class)
            ->fillForm(['email' => 'keep@example.com'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_admin_sets_legacy_user_email(): void
    {
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::SUPERADMIN);
        $legacy = $this->makeUser($this->section('ADC'));

        $this->editUser($admin, $legacy, ['email' => 'legacy@example.com'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('legacy@example.com', $legacy->fresh()->email);
    }

    public function test_admin_edit_rejects_duplicate_email(): void
    {
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::SUPERADMIN);
        $this->withEmail($this->makeUser($this->section('ADC')), 'taken@example.com');
        $target = $this->makeUser($this->section('ADC'));

        $this->editUser($admin, $target, ['email' => 'taken@example.com'])
            ->assertHasTableActionErrors(['email']);

        $this->assertNull($target->fresh()->email);
    }

    public function test_admin_edit_allows_soft_deleted_users_email(): void
    {
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::SUPERADMIN);
        $this->withEmail($this->makeUser($this->section('ADC')), 'old@example.com')->delete();
        $target = $this->makeUser($this->section('ADC'));

        $this->editUser($admin, $target, ['email' => 'old@example.com'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('old@example.com', $target->fresh()->email);
    }

    public function test_admin_edit_rejects_invalid_email(): void
    {
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::SUPERADMIN);
        $target = $this->makeUser($this->section('ADC'));

        $this->editUser($admin, $target, ['email' => 'bad@@'])
            ->assertHasTableActionErrors(['email']);

        $this->assertNull($target->fresh()->email);
    }

    public function test_admin_clearing_email_stores_null(): void
    {
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::SUPERADMIN);
        $target = $this->withEmail($this->makeUser($this->section('ADC')), 'clear@example.com');
        $this->makeUser($this->section('ADC')); // another empty-email user

        $this->editUser($admin, $target, ['email' => ''])
            ->assertHasNoTableActionErrors();

        $this->assertNull($target->fresh()->email);
    }
}
