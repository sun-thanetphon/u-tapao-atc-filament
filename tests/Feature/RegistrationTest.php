<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Mail\NewRegistrationMail;
use App\Models\Rank;
use App\Models\User;
use App\Providers\Filament\Auth\CustomRegister;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Mail::fake();
        RateLimiter::clear('livewire-rate-limiter:' . sha1(CustomRegister::class . '|register|127.0.0.1'));
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'rank_id' => Rank::query()->value('id'),
            'section_id' => $this->section('ADC'),
            'username' => 'newcomer',
            'firstname' => 'สมชาย',
            'lastname' => 'ใจดี',
            'email' => 'newcomer@example.com',
            'password' => 'password123',
            'passwordConfirmation' => 'password123',
        ], $overrides);
    }

    protected function submit(array $overrides = [], array $extra = [])
    {
        $component = Livewire::test(CustomRegister::class)->fillForm($this->payload($overrides));

        foreach ($extra as $key => $value) {
            $component->set("data.$key", $value);
        }

        return $component->call('register');
    }

    public function test_valid_registration_creates_pending_user_without_role_and_does_not_log_in(): void
    {
        $this->submit()->assertHasNoFormErrors();

        $user = User::where('username', 'newcomer')->firstOrFail();
        $this->assertSame('pending', $user->status);
        $this->assertSame('newcomer@example.com', $user->email);
        $this->assertCount(0, $user->roles);
        $this->assertFalse(auth()->check());
        $this->assertFalse(Filament::auth()->check());
    }

    public function test_duplicate_username_is_rejected_ignoring_case_and_spaces(): void
    {
        $this->makeUser($this->section('ADC'))->forceFill(['username' => 'tower01'])->save();

        $this->submit(['username' => ' Tower01 '])->assertHasFormErrors(['username']);
        $this->assertSame(1, User::where('username', 'tower01')->count());
    }

    public function test_username_of_soft_deleted_user_can_be_reused(): void
    {
        $old = $this->makeUser($this->section('ADC'));
        $old->forceFill(['username' => 'tower01'])->save();
        $old->delete();

        $this->submit(['username' => 'tower01'])->assertHasNoFormErrors();
        $this->assertSame(1, User::where('username', 'tower01')->count());
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->makeUser($this->section('ADC'))->forceFill(['email' => 'newcomer@example.com'])->save();

        $this->submit()->assertHasFormErrors(['email']);
        $this->assertSame(0, User::where('username', 'newcomer')->count());
    }

    public function test_role_and_status_cannot_be_injected(): void
    {
        $this->submit([], ['status' => 'active', 'role' => 'admin']);

        $user = User::where('username', 'newcomer')->firstOrFail();
        $this->assertSame('pending', $user->status);
        $this->assertCount(0, $user->roles);
    }

    public function test_password_must_be_8_chars_and_confirmed(): void
    {
        $this->submit(['password' => 'short', 'passwordConfirmation' => 'short'])->assertHasFormErrors(['password']);
        $this->submit(['passwordConfirmation' => 'different123'])->assertHasFormErrors(['password']);
        $this->assertSame(0, User::where('username', 'newcomer')->count());
    }

    public function test_filled_honeypot_creates_no_user(): void
    {
        $this->submit([], ['website' => 'http://spam.example']);

        $this->assertSame(0, User::where('username', 'newcomer')->count());
    }

    public function test_fourth_registration_from_same_ip_is_rate_limited(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->submit(['username' => "user$i", 'email' => "user$i@example.com"]);
        }
        $this->submit(['username' => 'user4', 'email' => 'user4@example.com']);

        $this->assertSame(3, User::whereIn('username', ['user1', 'user2', 'user3', 'user4'])->count());
        $this->assertSame(0, User::where('username', 'user4')->count());
    }

    public function test_approvers_with_email_are_notified(): void
    {
        $withEmail = $this->makeUser($this->section('ADC'), RoleEnum::ADMIN);
        $withEmail->forceFill(['email' => 'admin@example.com'])->save();
        $noEmail = $this->makeUser($this->section('ADC'), RoleEnum::ADMIN);
        $noEmail->forceFill(['email' => null])->save();

        $this->submit()->assertHasNoFormErrors();

        Mail::assertSent(NewRegistrationMail::class, fn ($mail) => $mail->hasTo('admin@example.com'));
        Mail::assertSent(NewRegistrationMail::class, 1);
        $this->assertSame(1, User::where('username', 'newcomer')->count());
    }

    public function test_mail_failure_does_not_block_registration(): void
    {
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::ADMIN);
        $admin->forceFill(['email' => 'admin@example.com'])->save();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->submit()->assertHasNoFormErrors();

        $this->assertSame('pending', User::where('username', 'newcomer')->value('status'));
    }
}
