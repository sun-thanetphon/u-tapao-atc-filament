<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Filament\Resources\LoginLogResource;
use App\Filament\Resources\LoginLogResource\Pages\ListLoginLogs;
use App\Models\LoginLog;
use App\Models\User;
use App\Providers\Filament\Auth\CustomLogin;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function attempt(string $username, string $password = 'password')
    {
        return Livewire::test(CustomLogin::class)
            ->fillForm(['username' => $username, 'password' => $password])
            ->call('authenticate');
    }

    public function test_successful_login_is_logged_and_sets_last_login(): void
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);

        $this->attempt($user->username);

        $log = LoginLog::query()->sole();
        $this->assertSame(LoginLog::EVENT_LOGIN, $log->event);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->username, $log->username);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_logged_as_failed_without_password(): void
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);

        try {
            $this->attempt($user->username, 'wrong-password');
        } catch (\Throwable) {
        }

        $log = LoginLog::query()->sole();
        $this->assertSame(LoginLog::EVENT_FAILED, $log->event);
        $this->assertSame($user->username, $log->username);
        $this->assertNull($user->fresh()->last_login_at);
        $this->assertStringNotContainsString('wrong-password', json_encode($log->getAttributes()));
    }

    public function test_pending_user_login_counts_as_failed_not_login(): void
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $user->forceFill(['status' => UserStatus::PENDING])->save();

        $this->attempt($user->username);

        $log = LoginLog::query()->sole();
        $this->assertSame(LoginLog::EVENT_FAILED, $log->event);
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_logout_is_logged(): void
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);

        $this->actingAs($user);
        auth()->logout();

        $this->assertSame([LoginLog::EVENT_LOGOUT], LoginLog::query()->pluck('event')->all());
    }

    public function test_only_super_admin_can_see_the_resource(): void
    {
        $section = $this->section('ADC');

        $this->actingAs($this->makeUser($section, RoleEnum::ADMIN));
        $this->assertFalse(LoginLogResource::canAccess());

        $this->actingAs($this->makeUser($section, RoleEnum::USER));
        $this->assertFalse(LoginLogResource::canAccess());

        $this->actingAs($this->makeUser($section, RoleEnum::SUPERADMIN));
        $this->assertTrue(LoginLogResource::canAccess());
    }

    public function test_super_admin_sees_rows_and_admin_gets_forbidden(): void
    {
        $section = $this->section('ADC');
        LoginLog::create(['username' => 'somebody', 'event' => LoginLog::EVENT_LOGIN, 'ip_address' => '203.0.113.7']);

        $this->actingAs($this->makeUser($section, RoleEnum::ADMIN))
            ->get(LoginLogResource::getUrl())
            ->assertForbidden();

        $this->actingAs($this->makeUser($section, RoleEnum::SUPERADMIN));
        Livewire::test(ListLoginLogs::class)
            ->assertSee('somebody')
            ->assertSee('203.0.113.7');
    }

    public function test_old_logs_are_pruned_on_login(): void
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $old = LoginLog::create(['username' => 'old', 'event' => LoginLog::EVENT_LOGIN]);
        $old->forceFill(['created_at' => now()->subDays(100)])->save();

        // สุ่มล้าง 1/50 ต่อการล็อกอิน: วนจนกว่าจะล้างจริง (กันเทสต์สุ่ม)
        for ($i = 0; $i < 400 && LoginLog::query()->where('username', 'old')->exists(); $i++) {
            event(new \Illuminate\Auth\Events\Login('web', $user, false));
        }

        $this->assertFalse(LoginLog::query()->where('username', 'old')->exists());
    }
}
