<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Filament\Pages\CredentialExpiry;
use App\Mail\CredentialExpiryMail;
use App\Models\CredentialReminder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class CredentialPageTest extends TestCase
{
    use RefreshDatabase;

    private string $sheetBody;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        Carbon::setTestNow('2026-10-19 08:00:00');
        config(['services.credential_sheet.id' => 'abc']);

        $this->sheetBody = "title,,,,\n"
            . "n,Name of Holder,Rating Expiry,Validity of licence,Validity of Medical Class 3,Valid Until\n"
            . "1,น.ต.สมคิด สุนทรเพราะ,\"August 31, 2029\",\"December 1, 2026\",\"September 16, 2029\",\"June 27, 2029\"\n"
            . "2,ร.ท.ไม่มี ในระบบ,\"August 31, 2029\",\"November 1, 2026\",,\n";

        Http::fake(['docs.google.com/*' => fn () => Http::response($this->sheetBody)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function holder(): User
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $user->forceFill(['firstname' => 'สมคิด', 'lastname' => 'สุนทรเพราะ', 'email' => 'somkid@example.com'])->save();

        return $user;
    }

    private function likhit(): User
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::ADMIN);
        $user->forceFill(['username' => 'limaromeo'])->save();

        return $user;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roles(): array
    {
        return ['user' => [RoleEnum::USER], 'admin' => [RoleEnum::ADMIN], 'super-admin' => [RoleEnum::SUPERADMIN]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function test_every_logged_in_user_can_view_the_list(string $role): void
    {
        $this->actingAs($this->makeUser($this->section('ADC'), $role));

        $this->assertTrue(CredentialExpiry::canAccess());
        $this->assertTrue(CredentialExpiry::shouldRegisterNavigation());
        $this->get(CredentialExpiry::getUrl())->assertOk()->assertSee('รายการวันหมดอายุ');
    }

    public function test_guest_cannot_view_the_list(): void
    {
        $this->assertFalse(CredentialExpiry::canAccess());
        $this->get(CredentialExpiry::getUrl())->assertRedirect();
    }

    public function test_user_sees_no_buttons_and_cannot_trigger_actions(): void
    {
        Mail::fake();
        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::USER));

        Livewire::test(CredentialExpiry::class)
            ->assertSee('รายการวันหมดอายุ')
            ->assertDontSee('รีเฟรชจากชีต')
            ->assertDontSee('ส่งอีเมลตัวอย่างถึงฉัน')
            ->call('refreshSheet')
            ->assertForbidden();

        Livewire::test(CredentialExpiry::class)->call('sendTest')->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_other_admin_sees_no_buttons_and_cannot_trigger_actions(): void
    {
        Mail::fake();
        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::ADMIN));

        Livewire::test(CredentialExpiry::class)
            ->assertDontSee('รีเฟรชจากชีต')
            ->assertDontSee('ส่งอีเมลตัวอย่างถึงฉัน')
            ->call('refreshSheet')
            ->assertForbidden();

        Livewire::test(CredentialExpiry::class)->call('sendTest')->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_likhit_can_refresh_but_not_send_test(): void
    {
        Mail::fake();
        $this->actingAs($this->likhit());
        Cache::put('credential-sheet-rows', [], 600);

        Livewire::test(CredentialExpiry::class)
            ->assertSee('รีเฟรชจากชีต')
            ->assertDontSee('ส่งอีเมลตัวอย่างถึงฉัน')
            ->call('refreshSheet')
            ->assertOk();

        // แคชถูกล้าง จึงอ่านชีตใหม่ตอน render รอบหลัง
        Http::assertSentCount(1);

        Livewire::test(CredentialExpiry::class)->call('sendTest')->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_likhit_username_without_admin_role_cannot_refresh(): void
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $user->forceFill(['username' => 'limaromeo'])->save();
        $this->actingAs($user);

        $this->assertFalse(CredentialExpiry::canRefreshSheet());
        Livewire::test(CredentialExpiry::class)->call('refreshSheet')->assertForbidden();
    }

    public function test_super_admin_sees_both_buttons_and_can_refresh(): void
    {
        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::SUPERADMIN));
        Cache::put('credential-sheet-rows', [], 600);

        Livewire::test(CredentialExpiry::class)
            ->assertSee('รีเฟรชจากชีต')
            ->assertSee('ส่งอีเมลตัวอย่างถึงฉัน')
            ->call('refreshSheet')
            ->assertOk();

        // แคชถูกล้าง จึงอ่านชีตใหม่ตอน render รอบหลัง
        Http::assertSentCount(1);
    }

    public function test_lists_items_nearest_first_with_reminder_state(): void
    {
        $holder = $this->holder();
        CredentialReminder::create(['user_id' => $holder->id, 'item' => 'licence', 'expires_on' => '2026-12-01', 'threshold' => 60]);
        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::ADMIN));

        Livewire::test(CredentialExpiry::class)
            ->assertSeeInOrder(['ร.ท.ไม่มี ในระบบ', 'น.ต.สมคิด สุนทรเพราะ'])
            ->assertSee('เตือนแล้ว')
            ->assertSee('ยังไม่ถึงกำหนดเตือน')
            ->assertSee('ส่งไม่ได้: ไม่พบบัญชีที่ชื่อตรงกัน');
    }

    public function test_unmatched_holders_are_listed_with_reason(): void
    {
        $this->holder();
        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::ADMIN));

        Livewire::test(CredentialExpiry::class)
            ->assertSee('รายชื่อที่ส่งเมลไม่ได้')
            ->assertSee('ไม่พบบัญชีที่ชื่อตรงกัน');
    }

    public function test_sheet_failure_shows_an_error_and_no_rows(): void
    {
        $this->sheetBody = '<html>Sign in</html>';
        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::ADMIN));

        Livewire::test(CredentialExpiry::class)
            ->assertSee('อ่านชีตไม่สำเร็จ')
            ->assertDontSee('รายการวันหมดอายุ');
    }

    public function test_send_test_mails_only_the_clicker_and_reserves_nothing(): void
    {
        Mail::fake();
        $this->holder();
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::SUPERADMIN);
        $admin->forceFill(['email' => 'admin@example.com'])->save();
        $this->actingAs($admin);

        Livewire::test(CredentialExpiry::class)->call('sendTest');

        Mail::assertSent(CredentialExpiryMail::class, 1);
        Mail::assertSent(CredentialExpiryMail::class, fn ($m) => $m->hasTo('admin@example.com') && $m->test);
        $this->assertSame(0, CredentialReminder::query()->count());
    }

    public function test_send_test_requires_super_admin(): void
    {
        Mail::fake();
        $this->actingAs($this->likhit());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        (new CredentialExpiry())->sendTest();
    }
}
