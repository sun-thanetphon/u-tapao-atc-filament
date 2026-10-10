<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use App\Providers\Filament\Auth\CustomRequestPasswordReset;
use App\Providers\Filament\Auth\CustomResetPassword;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordResetEmailTest extends TestCase
{
    use RefreshDatabase;

    private const NEUTRAL = 'หากอีเมลนี้มีอยู่ในระบบ เราได้ส่งลิงก์ตั้งรหัสผ่านใหม่ให้แล้ว';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        RateLimiter::clear($this->requestLimiterKey());
        RateLimiter::clear('livewire-rate-limiter:' . sha1(CustomResetPassword::class . '|resetPassword|127.0.0.1'));
    }

    protected function requestLimiterKey(): string
    {
        return 'livewire-rate-limiter:' . sha1(CustomRequestPasswordReset::class . '|request|127.0.0.1');
    }

    protected function userWithEmail(string $status = UserStatus::ACTIVE, string $email = 'person@example.com'): User
    {
        $user = $this->makeUser($this->section('ADC'));
        $user->forceFill(['email' => $email, 'status' => $status])->save();

        return $user;
    }

    protected function titles(): array
    {
        return collect(session('filament.notifications', []))->pluck('title')->all();
    }

    protected function requestReset(string $email)
    {
        return Livewire::test(CustomRequestPasswordReset::class)
            ->fillForm(['email' => $email])
            ->call('request');
    }

    protected function doReset(string $email, string $token, string $password = 'brand-new-pass')
    {
        return Livewire::test(CustomResetPassword::class, ['email' => $email, 'token' => $token])
            ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
            ->call('resetPassword');
    }

    public function test_active_user_with_email_receives_reset_link(): void
    {
        NotificationFacade::fake();
        $user = $this->userWithEmail();

        $this->requestReset('person@example.com')->assertHasNoFormErrors();

        NotificationFacade::assertSentToTimes($user, ResetPasswordNotification::class, 1);
        $this->assertSame([self::NEUTRAL], $this->titles());
    }

    public function test_unknown_email_gets_same_response_and_no_mail(): void
    {
        NotificationFacade::fake();
        $this->userWithEmail();

        $this->requestReset('nobody@example.com')->assertHasNoFormErrors();

        NotificationFacade::assertNothingSent();
        $this->assertSame([self::NEUTRAL], $this->titles());
    }

    public function test_pending_rejected_and_soft_deleted_users_get_same_response_and_no_mail(): void
    {
        NotificationFacade::fake();
        $this->userWithEmail(UserStatus::PENDING, 'pending@example.com');
        $this->userWithEmail(UserStatus::REJECTED, 'rejected@example.com');
        $this->userWithEmail(UserStatus::ACTIVE, 'deleted@example.com')->delete();

        foreach (['pending@example.com', 'rejected@example.com', 'deleted@example.com'] as $email) {
            session()->forget('filament.notifications');
            $this->requestReset($email)->assertHasNoFormErrors();
            $this->assertSame([self::NEUTRAL], $this->titles(), $email);
            RateLimiter::clear($this->requestLimiterKey());
        }

        NotificationFacade::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_mail_failure_is_swallowed_and_shows_same_message(): void
    {
        $this->userWithEmail();
        Event::listen(NotificationSending::class, fn () => throw new \RuntimeException('smtp down'));

        $this->requestReset('person@example.com')->assertHasNoFormErrors();

        $this->assertSame([self::NEUTRAL], $this->titles());
    }

    public function test_link_works_once(): void
    {
        $user = $this->userWithEmail();
        $token = Password::broker()->createToken($user);

        $this->doReset('person@example.com', $token)->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));

        session()->forget('filament.notifications');
        RateLimiter::clear('livewire-rate-limiter:' . sha1(CustomResetPassword::class . '|resetPassword|127.0.0.1'));
        $this->doReset('person@example.com', $token, 'another-pass-99');

        $this->assertFalse(Hash::check('another-pass-99', $user->fresh()->password));
        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertContains(CustomResetPassword::INVALID_TOKEN_MESSAGE, $this->titles());
    }

    public function test_reset_invalidates_existing_sessions(): void
    {
        $user = $this->userWithEmail();
        $other = $this->makeUser($this->section('ADC'));
        $user->forceFill(['remember_token' => 'old-token'])->save();

        foreach ([[$user->id, 'sess-a'], [$user->id, 'sess-b'], [$other->id, 'sess-other']] as [$uid, $id]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $uid, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);
        }

        $this->doReset('person@example.com', Password::broker()->createToken($user));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
    }

    public function test_expired_token_after_61_minutes_is_rejected(): void
    {
        $user = $this->userWithEmail();
        $token = Password::broker()->createToken($user);

        Carbon::setTestNow(now()->addMinutes(61));
        try {
            $this->doReset('person@example.com', $token);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertFalse(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertContains(CustomResetPassword::INVALID_TOKEN_MESSAGE, $this->titles());
    }

    public function test_token_just_under_an_hour_still_works(): void
    {
        $user = $this->userWithEmail();
        $token = Password::broker()->createToken($user);

        Carbon::setTestNow(now()->addMinutes(59));
        try {
            $this->doReset('person@example.com', $token);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
    }

    public function test_token_of_user_no_longer_active_is_rejected(): void
    {
        $user = $this->userWithEmail();
        $token = Password::broker()->createToken($user);
        $user->forceFill(['status' => UserStatus::REJECTED])->save();

        $this->doReset('person@example.com', $token);

        $this->assertFalse(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertContains(CustomResetPassword::INVALID_TOKEN_MESSAGE, $this->titles());
    }

    public function test_fourth_request_from_same_ip_is_rate_limited(): void
    {
        NotificationFacade::fake();
        $user = $this->userWithEmail();

        for ($i = 0; $i < 3; $i++) {
            $this->requestReset('person@example.com');
        }

        // หน้าต่างเป็นรายชั่วโมง ไม่ใช่รายนาที
        $this->assertGreaterThan(60, RateLimiter::availableIn($this->requestLimiterKey()));

        session()->forget('filament.notifications');
        $this->requestReset('person@example.com');

        $this->assertNotContains(self::NEUTRAL, $this->titles());
        $this->assertNotEmpty($this->titles());
        // ไม่มีการเรียก broker เพิ่ม: ยังได้เมลเพียงฉบับเดียวจากคำขอแรก
        NotificationFacade::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }

    public function test_page_shows_check_spam_hint(): void
    {
        $this->get(Filament::getRequestPasswordResetUrl())->assertOk()->assertSee('ไม่พบอีเมล? ตรวจโฟลเดอร์สแปม');
    }

    public function test_login_page_links_to_password_reset(): void
    {
        $this->get(Filament::getLoginUrl())->assertOk()->assertSee(Filament::getRequestPasswordResetUrl());
    }
}
