<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Mail\CredentialExpiryMail;
use App\Models\CredentialReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RemindCredentialsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Cache::flush();
        Carbon::setTestNow('2026-10-19 08:00:00');
        config(['services.credential_sheet.id' => 'abc', 'mail.reminder_test_recipient' => null]);

        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $user->forceFill(['firstname' => 'สมคิด', 'lastname' => 'สุนทรเพราะ', 'email' => 'somkid@example.com'])->save();

        $csv = "title,,,,\n"
            . "n,Name of Holder,Rating Expiry,Validity of licence,Validity of Medical Class 3,Valid Until\n"
            . "1,น.ต.สมคิด สุนทรเพราะ,\"August 31, 2029\",\"December 1, 2026\",\"September 16, 2029\",\"June 27, 2029\"\n";

        Http::fake(['docs.google.com/*' => Http::response($csv)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_prints_the_plan_and_sends_nothing(): void
    {
        Mail::fake();

        $this->artisan('credentials:remind --dry-run')
            ->expectsOutputToContain('[dry-run] จะส่ง 1 คน')
            ->doesntExpectOutputToContain('somkid@example.com')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertSame(0, CredentialReminder::query()->count());
    }

    public function test_real_run_sends_and_reports_counts_without_names_or_emails(): void
    {
        Mail::fake();

        $this->artisan('credentials:remind')
            ->expectsOutputToContain('ส่งแล้ว 1 ฉบับ')
            ->doesntExpectOutputToContain('somkid@example.com')
            ->doesntExpectOutputToContain('สมคิด')
            ->assertExitCode(0);

        Mail::assertSent(CredentialExpiryMail::class, 1);
    }

    public function test_sheet_failure_exits_1_with_a_thai_message(): void
    {
        Mail::fake();
        // stub แรกใน setUp ชนะเสมอ จึงต้องล้างก่อนจำลองชีตที่ถูกจำกัดสิทธิ์
        Http::clearResolvedInstances();
        Http::fake(['docs.google.com/*' => Http::response('<html>Sign in</html>')]);

        $this->artisan('credentials:remind')
            ->expectsOutputToContain('อ่านชีตไม่สำเร็จ')
            ->assertExitCode(1);

        Mail::assertNothingSent();
    }
}
