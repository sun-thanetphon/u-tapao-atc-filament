<?php

namespace Tests\Feature;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Enums\UserStatus;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Mail\RegistrationApprovedMail;
use App\Mail\RegistrationRejectedMail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Mail::fake();
    }

    protected function makeWithStatus(string $status, ?string $email = 'person@example.com'): User
    {
        $user = $this->makeUser($this->section('ADC'));
        $user->syncRoles([]);
        $user->forceFill(['status' => $status, 'email' => $email])->save();

        return $user->fresh();
    }

    protected function actingAdmin(string $role = RoleEnum::ADMIN): User
    {
        $admin = $this->makeUser($this->section('ADC'), $role);
        $this->actingAs($admin);

        return $admin;
    }

    protected function notificationTitles()
    {
        return collect(session('filament.notifications', []))->pluck('title');
    }

    public function test_admin_and_super_admin_can_approve_with_chosen_role(): void
    {
        foreach ([[RoleEnum::ADMIN, RoleEnum::USER], [RoleEnum::SUPERADMIN, RoleEnum::ADMIN]] as [$actorRole, $chosen]) {
            $actor = $this->actingAdmin($actorRole);
            $pending = $this->makeWithStatus(UserStatus::PENDING);
            $pending->forceFill(['rejected_reason' => 'เหตุผลเก่า'])->save();

            Livewire::test(ListUsers::class)
                ->set('activeTab', 'pending')
                ->callTableAction('approve', $pending, ['role' => $chosen])
                ->assertHasNoTableActionErrors();

            $pending->refresh();
            $this->assertSame(UserStatus::ACTIVE, $pending->status);
            $this->assertNull($pending->rejected_reason);
            $this->assertSame($actor->id, (int) $pending->approved_by);
            $this->assertNotNull($pending->approved_at);
            $this->assertSame([$chosen], $pending->getRoleNames()->all());
            Mail::assertSent(RegistrationApprovedMail::class, fn ($m) => $m->hasTo('person@example.com') && $m->role === $chosen);
        }
    }

    public function test_approve_without_email_succeeds_without_mail(): void
    {
        $this->actingAdmin();
        $legacy = $this->makeWithStatus(UserStatus::PENDING, null);

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'pending')
            ->callTableAction('approve', $legacy, ['role' => RoleEnum::USER])
            ->assertHasNoTableActionErrors();

        $this->assertSame(UserStatus::ACTIVE, $legacy->fresh()->status);
        $this->assertTrue($legacy->fresh()->hasRole(RoleEnum::USER));
        Mail::assertNothingSent();
    }

    public function test_mail_failure_shows_warning_but_still_approves(): void
    {
        $this->actingAdmin();
        $pending = $this->makeWithStatus(UserStatus::PENDING);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'pending')
            ->callTableAction('approve', $pending, ['role' => RoleEnum::USER]);

        $this->assertSame(UserStatus::ACTIVE, $pending->fresh()->status);
        $this->assertTrue($this->notificationTitles()->contains('ส่งอีเมลไม่สำเร็จ'));
    }

    public function test_plain_user_cannot_see_or_call_approve(): void
    {
        $pending = $this->makeWithStatus(UserStatus::PENDING);

        // user ธรรมดาเข้าหน้านี้ไม่ได้เลย
        $plain = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $this->actingAs($plain);
        Livewire::test(ListUsers::class)->assertForbidden();

        // ผู้ที่ดูรายชื่อได้แต่ไม่มีสิทธิ์อนุมัติ: ปุ่มซ่อน และเรียกตรงก็ไม่มีผล
        $viewer = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $viewer->givePermissionTo(PermissionEnum::USER_VIEW);
        $this->actingAs($viewer);

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'pending')
            ->assertTableActionHidden('approve', $pending)
            ->assertTableActionHidden('reject', $pending);

        $pending->refresh();
        $this->assertSame(UserStatus::PENDING, $pending->status);
        $this->assertCount(0, $pending->roles);
        Mail::assertNothingSent();
    }

    public function test_super_admin_role_is_not_an_option_and_is_rejected_server_side(): void
    {
        $this->actingAdmin();
        $pending = $this->makeWithStatus(UserStatus::PENDING);

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'pending')
            ->callTableAction('approve', $pending, ['role' => RoleEnum::SUPERADMIN])
            ->assertHasTableActionErrors(['role']);

        $pending->refresh();
        $this->assertSame(UserStatus::PENDING, $pending->status);
        $this->assertCount(0, $pending->roles);
        Mail::assertNothingSent();
    }

    public function test_only_pending_rows_can_be_approved(): void
    {
        $this->actingAdmin();
        $target = $this->makeWithStatus(UserStatus::PENDING);

        // สำเนาในหน่วยความจำยังเป็น pending แต่ในฐานข้อมูลมีคนอนุมัติตัดหน้าไปก่อนแล้ว
        $stale = User::find($target->id);
        User::where('id', $target->id)->update(['status' => UserStatus::ACTIVE]);
        $target->assignRole(RoleEnum::USER);

        $approve = new \ReflectionMethod(UserResource::class, 'approveRecords');
        $result = $approve->invoke(null, collect([$stale]), RoleEnum::ADMIN);

        $target->refresh();
        $this->assertSame(0, $result);
        $this->assertSame([RoleEnum::USER], $target->getRoleNames()->all());
        $this->assertNull($target->approved_by);
        Mail::assertNothingSent();
        $this->assertTrue($this->notificationTitles()->contains('รายการนี้ถูกดำเนินการไปแล้ว'));
    }

    public function test_approve_is_authorized_server_side_even_if_visibility_changes_midway(): void
    {
        $admin = $this->actingAdmin();
        $pending = $this->makeWithStatus(UserStatus::PENDING);

        $component = Livewire::test(ListUsers::class)
            ->set('activeTab', 'pending')
            ->mountTableAction('approve', $pending)
            ->setTableActionData(['role' => RoleEnum::ADMIN]);

        // สิทธิ์ถูกถอนหลังเปิดโมดัลแล้ว
        $admin->syncRoles([RoleEnum::USER]);
        $admin->givePermissionTo(PermissionEnum::USER_VIEW);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            $component->callMountedTableAction();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
        }

        $pending->refresh();
        $this->assertSame(UserStatus::PENDING, $pending->status);
        $this->assertCount(0, $pending->roles);
        Mail::assertNothingSent();

        // และตัวช่วยฝั่งเซิร์ฟเวอร์ปฏิเสธเองด้วย 403 แม้ถูกเรียกตรง
        $this->actingAs($admin->fresh());
        try {
            (new \ReflectionMethod(UserResource::class, 'approveRecords'))->invoke(null, collect([$pending]), RoleEnum::USER);
            $this->fail('expected 403');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(UserStatus::PENDING, $pending->fresh()->status);
    }

    public function test_reject_stores_reason_status_and_sends_mail(): void
    {
        $actor = $this->actingAdmin();
        $pending = $this->makeWithStatus(UserStatus::PENDING);

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'pending')
            ->callTableAction('reject', $pending, ['reason' => 'ไม่ใช่บุคลากร']);

        $pending->refresh();
        $this->assertSame(UserStatus::REJECTED, $pending->status);
        $this->assertSame('ไม่ใช่บุคลากร', $pending->rejected_reason);
        $this->assertSame($actor->id, (int) $pending->approved_by);
        $this->assertNotNull($pending->approved_at);
        $this->assertCount(0, $pending->roles);
        Mail::assertSent(RegistrationRejectedMail::class, fn ($m) => $m->hasTo('person@example.com') && $m->reason === 'ไม่ใช่บุคลากร');
    }

    public function test_pending_tab_shows_applicant_email_and_registration_date(): void
    {
        $this->actingAdmin();
        $pending = $this->makeWithStatus(UserStatus::PENDING, 'applicant@example.com');

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'pending')
            ->assertTableColumnExists('email')
            ->assertCanRenderTableColumn('email')
            ->assertCanRenderTableColumn('created_at')
            ->assertTableColumnStateSet('email', 'applicant@example.com', $pending)
            ->assertSee('applicant@example.com');
    }

    public function test_reopen_moves_rejected_back_to_pending(): void
    {
        $this->actingAdmin();
        $rejected = $this->makeWithStatus(UserStatus::REJECTED);

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'rejected')
            ->callTableAction('reopen', $rejected);

        $this->assertSame(UserStatus::PENDING, $rejected->fresh()->status);
    }

    public function test_bulk_approve_applies_one_role_to_all_selected_pending_users(): void
    {
        $this->actingAdmin();
        $a = $this->makeWithStatus(UserStatus::PENDING, 'a@example.com');
        $b = $this->makeWithStatus(UserStatus::PENDING, null);
        $active = $this->makeUser($this->section('ADC'), RoleEnum::USER);

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'pending')
            ->callTableBulkAction('approveSelected', [$a, $b, $active], ['role' => RoleEnum::ADMIN]);

        foreach ([$a, $b] as $u) {
            $u->refresh();
            $this->assertSame(UserStatus::ACTIVE, $u->status);
            $this->assertSame([RoleEnum::ADMIN], $u->getRoleNames()->all());
        }
        $this->assertSame([RoleEnum::USER], $active->fresh()->getRoleNames()->all());
        Mail::assertSent(RegistrationApprovedMail::class, 1);
    }

    public function test_bulk_approve_rejects_super_admin_role(): void
    {
        $this->actingAdmin();
        $a = $this->makeWithStatus(UserStatus::PENDING);

        Livewire::test(ListUsers::class)
            ->set('activeTab', 'pending')
            ->callTableBulkAction('approveSelected', [$a], ['role' => RoleEnum::SUPERADMIN])
            ->assertHasTableBulkActionErrors(['role']);

        $this->assertSame(UserStatus::PENDING, $a->fresh()->status);
        $this->assertCount(0, $a->fresh()->roles);
    }

    public function test_tabs_and_navigation_badge_count_pending(): void
    {
        $admin = $this->actingAdmin();
        $p1 = $this->makeWithStatus(UserStatus::PENDING);
        $this->makeWithStatus(UserStatus::PENDING);
        $rejected = $this->makeWithStatus(UserStatus::REJECTED);

        $this->assertSame('2', UserResource::getNavigationBadge());

        $tabs = (new ListUsers())->getTabs();
        $this->assertSame(['pending', 'active', 'rejected'], array_keys($tabs));
        $this->assertSame(2, $tabs['pending']->getBadge());

        Livewire::test(ListUsers::class)
            ->assertSet('activeTab', 'active')
            ->assertCanNotSeeTableRecords([$p1, $rejected])
            ->assertCanSeeTableRecords([$admin])
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$p1])
            ->assertCanNotSeeTableRecords([$admin, $rejected])
            ->set('activeTab', 'rejected')
            ->assertCanSeeTableRecords([$rejected])
            ->assertCanNotSeeTableRecords([$p1]);

        $viewer = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $viewer->givePermissionTo(PermissionEnum::USER_VIEW);
        $this->actingAs($viewer);
        $this->assertNull(UserResource::getNavigationBadge());
    }
}
