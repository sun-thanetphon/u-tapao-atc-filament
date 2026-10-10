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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
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
        // ส่งเมลทันทีแม้ตั้งคิวเป็น database (โฮสต์ไม่มี worker)
        config(['queue.default' => 'database']);
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

    protected function titles(): array
    {
        return collect(session('filament.notifications', []))->pluck('title')->all();
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

    /**
     * @return array<int, string>
     */
    protected function sentRecipients(): array
    {
        return collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($message) => $message->getEnvelope()->getRecipients()[0]->getAddress())
            ->all();
    }

    protected function assertRequestDoneBy(PasswordResetRequest $request, User $admin): void
    {
        $request->refresh();
        $this->assertSame(PasswordResetRequest::STATUS_DONE, $request->status);
        $this->assertSame($admin->id, (int) $request->handled_by);
        $this->assertNotNull($request->handled_at);
    }

    protected function assertRequestStillOpen(PasswordResetRequest $request): void
    {
        $request->refresh();
        $this->assertSame(PasswordResetRequest::STATUS_OPEN, $request->status);
        $this->assertNull($request->handled_by);
        $this->assertNull($request->handled_at);
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

    public function test_admin_adds_email_for_user_without_email_and_link_is_sent(): void
    {
        $admin = $this->actingAdmin();
        $legacy = $this->member();
        $request = $this->openRequestFor($legacy);

        Livewire::test(ListPasswordResetRequests::class)
            ->assertTableActionVisible('sendLink', $request)
            ->mountTableAction('sendLink', $request)
            // ช่องอีเมลแสดงเฉพาะบัญชีที่ยังไม่มีอีเมล; ค่าที่มีช่องว่างหัวท้ายถูกตัดออก
            ->set('mountedTableActionsData.0.email', '  legacy@example.com  ')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('legacy@example.com', $legacy->fresh()->email);
        $this->assertSame(['legacy@example.com'], $this->sentRecipients());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'legacy@example.com')->count());
        $this->assertRequestDoneBy($request, $admin);
    }

    public function test_duplicate_email_is_rejected_without_changes(): void
    {
        $this->actingAdmin();
        $this->member(email: 'taken@example.com');
        $legacy = $this->member();
        $request = $this->openRequestFor($legacy);

        Livewire::test(ListPasswordResetRequests::class)
            ->callTableAction('sendLink', $request, ['email' => 'taken@example.com'])
            ->assertHasTableActionErrors(['email' => 'unique']);

        $this->assertNull($legacy->fresh()->email);
        $this->assertSame([], $this->sentRecipients());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertRequestStillOpen($request);

        // เรียกตรงก็ถูกตรวจซ้ำฝั่งเซิร์ฟเวอร์
        PasswordResetRequestResource::sendLinkFor($request, 'taken@example.com');
        $this->assertNull($legacy->fresh()->email);
        $this->assertRequestStillOpen($request);
        $this->assertSame([], $this->sentRecipients());
    }

    public function test_email_of_soft_deleted_user_can_be_reused(): void
    {
        $admin = $this->actingAdmin();
        $this->member(email: 'old@example.com')->delete();
        $legacy = $this->member();
        $request = $this->openRequestFor($legacy);

        Livewire::test(ListPasswordResetRequests::class)
            ->callTableAction('sendLink', $request, ['email' => 'old@example.com'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('old@example.com', $legacy->fresh()->email);
        $this->assertRequestDoneBy($request, $admin);
    }

    public function test_invalid_or_missing_email_is_rejected(): void
    {
        $this->actingAdmin();
        $legacy = $this->member();
        $request = $this->openRequestFor($legacy);

        Livewire::test(ListPasswordResetRequests::class)
            ->callTableAction('sendLink', $request, ['email' => 'not-an-email'])
            ->assertHasTableActionErrors(['email' => 'email']);

        Livewire::test(ListPasswordResetRequests::class)
            ->callTableAction('sendLink', $request, ['email' => ''])
            ->assertHasTableActionErrors(['email' => 'required']);

        PasswordResetRequestResource::sendLinkFor($request, 'not-an-email');
        PasswordResetRequestResource::sendLinkFor($request, null);

        $this->assertNull($legacy->fresh()->email);
        $this->assertSame([], $this->sentRecipients());
        $this->assertRequestStillOpen($request);
    }

    public function test_user_with_existing_email_gets_link_there_and_tampered_email_is_ignored(): void
    {
        $admin = $this->actingAdmin();
        $user = $this->member(email: 'person@example.com');
        $request = $this->openRequestFor($user);

        // ฟอร์มไม่มีช่องอีเมล และค่าที่ยัดเข้ามาทาง Livewire ถูกเมิน
        $component = Livewire::test(ListPasswordResetRequests::class)
            ->assertTableActionVisible('sendLink', $request)
            ->mountTableAction('sendLink', $request);
        $this->assertSame([], $component->instance()->getMountedTableActionForm()?->getComponents() ?? []);

        $component
            ->set('mountedTableActionsData.0.email', 'evil@example.com')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('person@example.com', $user->fresh()->email);
        $this->assertSame(['person@example.com'], $this->sentRecipients());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'person@example.com')->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'evil@example.com')->count());
        $this->assertRequestDoneBy($request, $admin);

        // เรียกตรงพร้อมอีเมลอื่น: ยังส่งไปที่อีเมลเดิมเท่านั้น
        DB::table('password_reset_tokens')->delete();
        $second = $this->openRequestFor($user);
        PasswordResetRequestResource::sendLinkFor($second, 'evil@example.com');

        $this->assertSame('person@example.com', $user->fresh()->email);
        $this->assertSame(['person@example.com', 'person@example.com'], $this->sentRecipients());
        $this->assertRequestDoneBy($second, $admin);
    }

    public function test_failure_to_send_rolls_back_email_and_keeps_request_open(): void
    {
        $this->actingAdmin();
        $legacy = $this->member();
        $request = $this->openRequestFor($legacy);
        Event::listen(NotificationSending::class, fn () => throw new \RuntimeException('smtp down'));

        Livewire::test(ListPasswordResetRequests::class)
            ->callTableAction('sendLink', $request, ['email' => 'legacy@example.com'])
            ->assertHasNoTableActionErrors();

        $this->assertNull($legacy->fresh()->email);
        $this->assertRequestStillOpen($request);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame([], $this->sentRecipients());
        $this->assertContains('ส่งลิงก์ไม่สำเร็จ', $this->titles());
    }

    public function test_already_handled_request_is_not_processed_twice(): void
    {
        $this->actingAdmin();
        $legacy = $this->member();
        $request = $this->openRequestFor($legacy);
        $request->forceFill(['status' => PasswordResetRequest::STATUS_DONE])->save();

        PasswordResetRequestResource::sendLinkFor($request, 'legacy@example.com');

        $this->assertNull($legacy->fresh()->email);
        $this->assertSame([], $this->sentRecipients());
        $this->assertContains('รายการนี้ถูกดำเนินการไปแล้ว', $this->titles());
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

    public function test_plain_user_cannot_call_action_directly(): void
    {
        $legacy = $this->member();
        $request = $this->openRequestFor($legacy);

        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::USER));

        try {
            PasswordResetRequestResource::sendLinkFor($request, 'legacy@example.com');
            $this->fail('sendLinkFor should be forbidden');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertNull($legacy->fresh()->email);
        $this->assertRequestStillOpen($request);
        $this->assertSame([], $this->sentRecipients());
    }

    public function test_admin_cannot_reset_super_admin_id_1(): void
    {
        $root = User::forceCreate([
            'id' => 1,
            'rank_id' => Rank::query()->value('id'),
            'section_id' => $this->section('ADC'),
            'username' => 'root_admin',
            'firstname' => 'ผู้ดูแล',
            'lastname' => 'สูงสุด',
            'password' => 'root-original-pass',
            'status' => UserStatus::ACTIVE,
        ]);
        $root->assignRole(RoleEnum::SUPERADMIN);
        $rootRequest = $this->openRequestFor($root);

        $otherAdmin = $this->member(role: RoleEnum::ADMIN);
        $otherRequest = $this->openRequestFor($otherAdmin);

        $admin = $this->actingAdmin();

        Livewire::test(ListPasswordResetRequests::class)
            ->assertTableActionHidden('sendLink', $rootRequest)
            ->assertTableActionVisible('sendLink', $otherRequest);

        // บังคับฝั่งเซิร์ฟเวอร์ด้วย แม้เรียกตรง
        try {
            PasswordResetRequestResource::sendLinkFor($rootRequest, 'attacker@example.com');
            $this->fail('sendLinkFor should be forbidden for id 1');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNull($root->fresh()->email);
        $this->assertRequestStillOpen($rootRequest);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        // admin ส่งลิงก์ให้ admin คนอื่นได้
        Livewire::test(ListPasswordResetRequests::class)
            ->callTableAction('sendLink', $otherRequest, ['email' => 'other-admin@example.com'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('other-admin@example.com', $otherAdmin->fresh()->email);
        $this->assertRequestDoneBy($otherRequest, $admin);

        // super-admin จัดการ id 1 ได้
        $super = $this->actingAdmin(RoleEnum::SUPERADMIN);
        Livewire::test(ListPasswordResetRequests::class)
            ->assertTableActionVisible('sendLink', $rootRequest)
            ->callTableAction('sendLink', $rootRequest, ['email' => 'root@example.com'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('root@example.com', $root->fresh()->email);
        $this->assertRequestDoneBy($rootRequest, $super);
    }

    public function test_temporary_password_and_forced_change_are_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'must_change_password'));
        $this->assertFalse(class_exists('App\\Http\\Middleware\\ForcePasswordChange'));
        $this->assertFalse(method_exists(PasswordResetRequestResource::class, 'setTemporaryPasswordFor'));
    }
}
