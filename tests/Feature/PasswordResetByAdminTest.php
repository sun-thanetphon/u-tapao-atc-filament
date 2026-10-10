<?php

namespace Tests\Feature;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Filament\Resources\PasswordResetRequestResource;
use App\Filament\Resources\PasswordResetRequestResource\Pages\ListPasswordResetRequests;
use App\Models\PasswordResetRequest;
use App\Models\Rank;
use App\Models\User;
use App\Providers\Filament\Auth\CustomRequestPasswordReset;
use App\Providers\Filament\Profile\ProfileEditCustom;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PasswordResetByAdminTest extends TestCase
{
    use RefreshDatabase;

    private const NEUTRAL = 'หากชื่อผู้ใช้นี้มีอยู่ในระบบ เราได้ส่งคำขอถึงผู้ดูแลระบบแล้ว';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        RateLimiter::clear($this->usernameLimiterKey());
    }

    protected function usernameLimiterKey(): string
    {
        return 'livewire-rate-limiter:' . sha1(CustomRequestPasswordReset::class . '|requestByUsername|127.0.0.1');
    }

    protected function member(string $status = UserStatus::ACTIVE, ?string $email = null, string $role = RoleEnum::USER): User
    {
        $user = $this->makeUser($this->section('ADC'), $role);
        $user->forceFill(['email' => $email, 'status' => $status])->save();

        return $user->fresh();
    }

    protected function actingAdmin(string $role = RoleEnum::ADMIN): User
    {
        $admin = $this->makeUser($this->section('ADC'), $role);
        $this->actingAs($admin);

        return $admin;
    }

    protected function notifications()
    {
        return collect(session('filament.notifications', []));
    }

    protected function titles(): array
    {
        return $this->notifications()->pluck('title')->all();
    }

    protected function requestByUsername(string $username)
    {
        return Livewire::test(CustomRequestPasswordReset::class)
            ->fillForm(['username' => $username], 'usernameForm')
            ->call('requestByUsername');
    }

    protected function openRequestFor(User $user): PasswordResetRequest
    {
        return PasswordResetRequest::create(['user_id' => $user->id]);
    }

    public function test_request_by_username_creates_one_open_request_and_repeat_reuses_it(): void
    {
        $user = $this->member();

        $this->requestByUsername($user->username)->assertHasNoFormErrors([], 'usernameForm');
        $this->assertSame([self::NEUTRAL], $this->titles());

        $first = PasswordResetRequest::query()->where('user_id', $user->id)->sole();
        $this->assertSame(PasswordResetRequest::STATUS_OPEN, $first->status);

        session()->forget('filament.notifications');
        $this->requestByUsername($user->username);

        $this->assertSame([self::NEUTRAL], $this->titles());
        $this->assertSame(1, PasswordResetRequest::query()->where('user_id', $user->id)->count());
        $this->assertSame($first->id, PasswordResetRequest::query()->where('user_id', $user->id)->sole()->id);

        // เมื่อคำขอเดิมปิดไปแล้ว คำขอใหม่จึงได้แถวใหม่
        $first->forceFill(['status' => PasswordResetRequest::STATUS_DONE])->save();
        $this->requestByUsername($user->username);

        $this->assertSame(2, PasswordResetRequest::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, PasswordResetRequest::query()->where('user_id', $user->id)->where('status', PasswordResetRequest::STATUS_OPEN)->count());
    }

    public function test_unknown_or_non_active_username_gets_same_response_and_creates_nothing(): void
    {
        $pending = $this->member(UserStatus::PENDING);
        $rejected = $this->member(UserStatus::REJECTED);
        $deleted = $this->member();
        $deleted->delete();

        foreach (['no_such_user', $pending->username, $rejected->username, $deleted->username] as $username) {
            session()->forget('filament.notifications');
            $this->requestByUsername($username)->assertHasNoFormErrors([], 'usernameForm');
            $this->assertSame([self::NEUTRAL], $this->titles(), $username);
            RateLimiter::clear($this->usernameLimiterKey());
        }

        $this->assertSame(0, PasswordResetRequest::query()->count());
    }

    public function test_fourth_request_is_rate_limited(): void
    {
        $first = $this->member();
        $second = $this->member();

        for ($i = 0; $i < 3; $i++) {
            $this->requestByUsername($first->username);
        }

        // หน้าต่างเป็นรายชั่วโมง
        $this->assertGreaterThan(60, RateLimiter::availableIn($this->usernameLimiterKey()));

        session()->forget('filament.notifications');
        $this->requestByUsername($second->username);

        $this->assertNotContains(self::NEUTRAL, $this->titles());
        $this->assertNotEmpty($this->titles());
        $this->assertSame(0, PasswordResetRequest::query()->where('user_id', $second->id)->count());
        $this->assertSame(1, PasswordResetRequest::query()->where('user_id', $first->id)->count());
    }

    public function test_email_form_still_works_alongside_username_form(): void
    {
        $this->get(Filament::getRequestPasswordResetUrl())
            ->assertOk()
            ->assertSee('ไม่พบอีเมล? ตรวจโฟลเดอร์สแปม')
            ->assertSee('ไม่มีอีเมลในระบบ?');
    }

    public function test_admin_sets_temporary_password(): void
    {
        $logFile = storage_path('logs/laravel.log');
        $logBefore = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message . ' ' . json_encode($event->context);
        });

        $admin = $this->actingAdmin();
        $user = $this->member();
        $oldHash = $user->password;
        $request = $this->openRequestFor($user);

        Livewire::test(ListPasswordResetRequests::class)
            ->assertTableActionVisible('setTemporaryPassword', $request)
            ->callTableAction('setTemporaryPassword', $request)
            ->assertHasNoTableActionErrors();

        // รหัสผ่านแสดงในแจ้งเตือนครั้งเดียว
        $bodies = $this->notifications()->pluck('body')->filter()->values();
        $withPassword = $bodies->filter(fn ($body) => preg_match('/<code>(.+?)<\/code>/', (string) $body));
        $this->assertCount(1, $withPassword);
        preg_match('/<code>(.+?)<\/code>/', (string) $withPassword->first(), $m);
        $plain = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        $this->assertSame(12, mb_strlen($plain));

        $user->refresh();
        $this->assertNotSame($oldHash, $user->password);
        $this->assertTrue(Hash::check($plain, $user->password));
        $this->assertNotSame($plain, $user->password);
        $this->assertTrue($user->must_change_password);

        $request->refresh();
        $this->assertSame(PasswordResetRequest::STATUS_DONE, $request->status);
        $this->assertSame($admin->id, (int) $request->handled_by);
        $this->assertNotNull($request->handled_at);

        // ไม่ถูกบันทึกลง log หรือฐานข้อมูลเป็นข้อความธรรมดา
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($plain, $line);
        }
        $logAfter = is_file($logFile) ? (string) file_get_contents($logFile) : '';
        $this->assertStringNotContainsString($plain, substr($logAfter, strlen($logBefore)));
        $this->assertStringNotContainsString($plain, $logAfter);
        $this->assertSame(0, DB::table('users')->where('password', $plain)->count());
        $this->assertStringNotContainsString($plain, json_encode(DB::table('password_reset_requests')->get()));

        // เรียกซ้ำกับคำขอที่ปิดแล้ว ไม่เปลี่ยนรหัสผ่านอีก
        $hashAfter = $user->password;
        session()->forget('filament.notifications');
        PasswordResetRequestResource::setTemporaryPasswordFor($request);
        $this->assertSame($hashAfter, $user->fresh()->password);
        $this->assertContains('รายการนี้ถูกดำเนินการไปแล้ว', $this->titles());
    }

    public function test_admin_can_send_link_only_if_user_has_email(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->actingAdmin();
        $withEmail = $this->member(email: 'person@example.com');
        $withoutEmail = $this->member();
        $requestA = $this->openRequestFor($withEmail);
        $requestB = $this->openRequestFor($withoutEmail);

        Livewire::test(ListPasswordResetRequests::class)
            ->assertTableActionVisible('sendLink', $requestA)
            ->assertTableActionHidden('sendLink', $requestB)
            ->callTableAction('sendLink', $requestA)
            ->assertHasNoTableActionErrors();

        // ส่งทันที ไม่เข้าคิว (โฮสต์ไม่มี worker)
        $this->assertSame(0, DB::table('jobs')->count());
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('person@example.com', $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'person@example.com')->count());

        $requestA->refresh();
        $this->assertSame(PasswordResetRequest::STATUS_DONE, $requestA->status);
        $this->assertSame($admin->id, (int) $requestA->handled_by);
        $this->assertNotNull($requestA->handled_at);

        // เรียกตรงกับผู้ใช้ที่ไม่มีอีเมล: ไม่มีผล
        PasswordResetRequestResource::sendLinkFor($requestB);
        $this->assertSame(PasswordResetRequest::STATUS_OPEN, $requestB->fresh()->status);
        $this->assertNull($requestB->fresh()->handled_by);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_plain_user_cannot_see_or_open_the_resource(): void
    {
        $this->openRequestFor($this->member());

        $plain = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $this->actingAs($plain);
        $this->assertFalse(PasswordResetRequestResource::canAccess());
        $this->assertNull(PasswordResetRequestResource::getNavigationBadge());
        $this->get(PasswordResetRequestResource::getUrl('index'))->assertForbidden();

        // ดูรายชื่อผู้ใช้ได้ แต่ไม่มีสิทธิ์อนุมัติ ก็เข้าไม่ได้
        // (ล้าง session ก่อนสลับผู้ใช้ ไม่งั้น AuthenticateSession จะ logout เพราะ hash รหัสผ่านไม่ตรง)
        $viewer = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $viewer->givePermissionTo(PermissionEnum::USER_VIEW);
        $this->flushSession();
        $this->actingAs($viewer);
        $this->assertFalse(PasswordResetRequestResource::canAccess());
        $this->assertNull(PasswordResetRequestResource::getNavigationBadge());
        $this->get(PasswordResetRequestResource::getUrl('index'))->assertForbidden();

        $this->flushSession();
        $this->actingAdmin();
        $this->assertTrue(PasswordResetRequestResource::canAccess());
        $this->assertSame('1', PasswordResetRequestResource::getNavigationBadge());
        $this->get(PasswordResetRequestResource::getUrl('index'))->assertOk()->assertSee('คำขอรีเซ็ตรหัสผ่าน');

        PasswordResetRequest::query()->update(['status' => PasswordResetRequest::STATUS_DONE]);
        $this->assertNull(PasswordResetRequestResource::getNavigationBadge());

        $this->actingAs($plain);
        Livewire::test(ListPasswordResetRequests::class)->assertForbidden();
    }

    public function test_admin_cannot_reset_super_admin_id_1(): void
    {
        $root = User::forceCreate([
            'id' => 1,
            'rank_id' => Rank::query()->value('id'),
            'section_id' => $this->section('ADC'),
            'username' => 'root_admin',
            'email' => 'root@example.com',
            'firstname' => 'ผู้ดูแล',
            'lastname' => 'สูงสุด',
            'password' => 'root-original-pass',
            'status' => UserStatus::ACTIVE,
        ]);
        $root->assignRole(RoleEnum::SUPERADMIN);
        $rootHash = $root->fresh()->password;
        $rootRequest = $this->openRequestFor($root);

        $otherAdmin = $this->member(role: RoleEnum::ADMIN);
        $otherRequest = $this->openRequestFor($otherAdmin);

        $this->actingAdmin();

        Livewire::test(ListPasswordResetRequests::class)
            ->assertTableActionHidden('setTemporaryPassword', $rootRequest)
            ->assertTableActionHidden('sendLink', $rootRequest)
            ->assertTableActionVisible('setTemporaryPassword', $otherRequest);

        // บังคับฝั่งเซิร์ฟเวอร์ด้วย แม้เรียกตรง
        foreach (['setTemporaryPasswordFor', 'sendLinkFor'] as $method) {
            try {
                PasswordResetRequestResource::{$method}($rootRequest);
                $this->fail("{$method} should be forbidden");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame($rootHash, $root->fresh()->password);
        $this->assertFalse($root->fresh()->must_change_password);
        $this->assertSame(PasswordResetRequest::STATUS_OPEN, $rootRequest->fresh()->status);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        // admin รีเซ็ต admin คนอื่นได้
        Livewire::test(ListPasswordResetRequests::class)->callTableAction('setTemporaryPassword', $otherRequest);
        $this->assertTrue($otherAdmin->fresh()->must_change_password);

        // super-admin รีเซ็ต id 1 ได้
        $this->actingAdmin(RoleEnum::SUPERADMIN);
        Livewire::test(ListPasswordResetRequests::class)
            ->assertTableActionVisible('setTemporaryPassword', $rootRequest)
            ->callTableAction('setTemporaryPassword', $rootRequest);
        $this->assertNotSame($rootHash, $root->fresh()->password);
        $this->assertSame(PasswordResetRequest::STATUS_DONE, $rootRequest->fresh()->status);
    }

    public function test_plain_user_cannot_call_actions_directly(): void
    {
        $user = $this->member(email: 'person@example.com');
        $hash = $user->password;
        $request = $this->openRequestFor($user);

        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::USER));

        foreach (['setTemporaryPasswordFor', 'sendLinkFor'] as $method) {
            try {
                PasswordResetRequestResource::{$method}($request);
                $this->fail("{$method} should be forbidden");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(PasswordResetRequest::STATUS_OPEN, $request->fresh()->status);
    }

    public function test_temp_password_user_is_redirected_from_any_panel_url(): void
    {
        $user = $this->member();
        $user->forceFill(['must_change_password' => true])->save();
        $this->actingAs($user);

        $this->get('/admin')->assertRedirect(Filament::getProfileUrl());
        $this->get(Filament::getUrl())->assertRedirect(Filament::getProfileUrl());

        // หน้าโปรไฟล์ไม่ redirect วน และแสดงคำแนะนำภาษาไทย
        $this->get(Filament::getProfileUrl())
            ->assertOk()
            ->assertSee('คุณกำลังใช้รหัสผ่านชั่วคราว');

        // ออกจากระบบได้ตามปกติ
        $this->post(Filament::getLogoutUrl())->assertRedirect(Filament::getLoginUrl());
        $this->assertGuest();
    }

    public function test_user_without_flag_is_not_redirected(): void
    {
        $this->actingAs($this->member());

        $this->get(Filament::getUrl())->assertOk();
    }

    public function test_changing_password_on_profile_clears_must_change_password(): void
    {
        $user = $this->member();
        $user->forceFill(['must_change_password' => true, 'password' => 'temp-pass-123'])->save();
        $this->actingAs($user);

        // ต้องกรอกรหัสผ่านใหม่
        Livewire::test(ProfileEditCustom::class)
            ->fillForm(['password' => '', 'passwordConfirmation' => ''])
            ->call('save')
            ->assertHasFormErrors(['password' => 'required']);
        $this->assertTrue($user->fresh()->must_change_password);

        // ใช้รหัสชั่วคราวเดิมซ้ำไม่ได้
        Livewire::test(ProfileEditCustom::class)
            ->fillForm(['password' => 'temp-pass-123', 'passwordConfirmation' => 'temp-pass-123'])
            ->call('save')
            ->assertHasFormErrors(['password']);
        $this->assertTrue($user->fresh()->must_change_password);

        Livewire::test(ProfileEditCustom::class)
            ->fillForm(['password' => 'brand-new-pass-1', 'passwordConfirmation' => 'brand-new-pass-1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('brand-new-pass-1', $user->password));

        // หลังเปลี่ยนแล้ว เข้าแผงได้ตามปกติ
        $this->get(Filament::getUrl())->assertOk();
    }

    public function test_profile_save_through_livewire_http_endpoint_works_while_flag_is_set(): void
    {
        $user = $this->member();
        $user->forceFill(['must_change_password' => true])->save();
        $this->actingAs($user);

        $html = $this->get(Filament::getProfileUrl())->assertOk()->getContent();

        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        $snapshot = collect($matches[1])
            ->map(fn ($raw) => html_entity_decode($raw, ENT_QUOTES | ENT_HTML5))
            ->first(fn ($json) => str_contains(json_decode($json, true)['memo']['name'] ?? '', 'profile-edit-custom'));
        $this->assertNotNull($snapshot, 'profile component snapshot not found');

        $this->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app('livewire')->getUpdateUri(), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => ['data.password' => 'brand-new-pass-1', 'data.passwordConfirmation' => 'brand-new-pass-1'],
                    'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
                ]],
            ])
            ->assertOk();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('brand-new-pass-1', $user->password));
    }

    public function test_profile_without_flag_keeps_optional_password(): void
    {
        $user = $this->member();
        $hash = $user->password;
        $this->actingAs($user);

        Livewire::test(ProfileEditCustom::class)
            ->fillForm(['password' => '', 'passwordConfirmation' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertFalse($user->fresh()->must_change_password);
    }
}
