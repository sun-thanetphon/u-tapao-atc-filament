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

    public function test_only_admin_and_super_admin_can_access(): void
    {
        $section = $this->section('ADC');

        $this->actingAs($this->makeUser($section, RoleEnum::USER));
        $this->assertFalse(CredentialExpiry::canAccess());
        $this->get(CredentialExpiry::getUrl())->assertForbidden();

        $this->actingAs($this->makeUser($section, RoleEnum::ADMIN));
        $this->assertTrue(CredentialExpiry::canAccess());

        $this->actingAs($this->makeUser($section, RoleEnum::SUPERADMIN));
        $this->assertTrue(CredentialExpiry::canAccess());
    }

    public function test_lists_items_nearest_first_with_reminder_state(): void
    {
        $holder = $this->holder();
        CredentialReminder::create(['user_id' => $holder->id, 'item' => 'licence', 'expires_on' => '2026-12-01']);
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
        $admin = $this->makeUser($this->section('ADC'), RoleEnum::ADMIN);
        $admin->forceFill(['email' => 'admin@example.com'])->save();
        $this->actingAs($admin);

        Livewire::test(CredentialExpiry::class)->call('sendTest');

        Mail::assertSent(CredentialExpiryMail::class, 1);
        Mail::assertSent(CredentialExpiryMail::class, fn ($m) => $m->hasTo('admin@example.com') && $m->test);
        $this->assertSame(0, CredentialReminder::query()->count());
    }

    public function test_send_test_requires_access(): void
    {
        Mail::fake();
        $this->actingAs($this->makeUser($this->section('ADC'), RoleEnum::USER));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        (new CredentialExpiry())->sendTest();
    }
}
