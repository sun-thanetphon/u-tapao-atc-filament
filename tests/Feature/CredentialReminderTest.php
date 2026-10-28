<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
use App\Mail\CredentialDigestMail;
use App\Mail\CredentialExpiryMail;
use App\Models\CredentialReminder;
use App\Models\User;
use App\Support\CredentialReminderService;
use App\Support\CredentialSheetException;
use App\Support\MailQuota;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CredentialReminderTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
        Cache::flush();
        config(['mail.reminder_test_recipient' => null, 'mail.daily_cap_for_reminders' => 40, 'mail.reminder_admin_recipients' => []]);
        $this->today = Carbon::parse('2026-10-19', 'Asia/Bangkok');
    }

    private function person(string $first = 'สมคิด', string $last = 'สุนทรเพราะ', ?string $email = 'somkid@example.com'): User
    {
        $user = $this->makeUser($this->section('ADC'), RoleEnum::USER);
        $user->forceFill(['firstname' => $first, 'lastname' => $last, 'email' => $email])->save();

        return $user;
    }

    /** @param array<string, string> $items kind => 'Y-m-d' */
    private function row(string $name, array $items): array
    {
        return [
            'name' => $name,
            'items' => array_map(fn (string $d) => Carbon::parse($d, 'Asia/Bangkok'), $items),
            'unreadable' => [],
        ];
    }

    private function remind(array $rows, bool $dry = false): array
    {
        return app(CredentialReminderService::class)->run($dry, $rows, $this->today);
    }

    public function test_reminds_at_exactly_90_days_but_not_91(): void
    {
        Mail::fake();
        $this->person();

        $this->assertSame(0, $this->remind([$this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2027-01-18'])])['sent']);

        $result = $this->remind([$this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2027-01-17'])]);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(90, $result['plan'][0]['items'][0]['days_left']);
        Mail::assertSent(CredentialExpiryMail::class, 1);
    }

    public function test_expired_items_are_included_and_flagged(): void
    {
        Mail::fake();
        $this->person();

        $result = $this->remind([$this->row('น.ต.สมคิด สุนทรเพราะ', ['medical' => '2026-10-01'])]);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(-18, $result['plan'][0]['items'][0]['days_left']);
        Mail::assertSent(CredentialExpiryMail::class, fn ($m) => str_contains($m->render(), 'หมดอายุแล้ว'));
    }

    public function test_one_mail_covers_all_due_items_of_a_person(): void
    {
        Mail::fake();
        $this->person();

        $result = $this->remind([$this->row('น.ต.สมคิด สุนทรเพราะ', [
            'rating' => '2026-12-01', 'licence' => '2026-11-01', 'medical' => '2029-01-01', 'language' => '2026-12-31',
        ])]);

        $this->assertSame(1, $result['sent']);
        Mail::assertSent(CredentialExpiryMail::class, fn ($m) => count($m->items) === 3 && $m->hasTo('somkid@example.com'));
        // เกณฑ์ที่ถึงแล้วและที่กว้างกว่าจองหมด: rating 43 วัน = 90+60, licence 13 วัน = 90+60+30, language 73 วัน = 90
        $this->assertSame(6, CredentialReminder::query()->count());
    }

    public function test_second_run_does_not_send_again_but_a_renewed_date_does(): void
    {
        Mail::fake();
        $this->person();
        $row = $this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01']);

        $this->assertSame(1, $this->remind([$row])['sent']);
        $this->assertSame(0, $this->remind([$row])['sent']);

        $renewed = $this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2027-01-10']);

        $this->assertSame(1, $this->remind([$renewed])['sent']);
        Mail::assertSent(CredentialExpiryMail::class, 2);
    }

    public function test_transport_failure_releases_reservation_so_next_run_retries(): void
    {
        $this->person();
        $row = $this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01']);

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        $failed = $this->remind([$row]);

        $this->assertSame(1, $failed['failed']);
        $this->assertSame(0, CredentialReminder::query()->count());

        // เอา mock ออกจาก container ด้วย ไม่งั้น Mail::fake() จะห่อ mock ตัวเดิม (MailFake เรียก getDefaultDriver() ตอนสร้าง)
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstances();
        Mail::fake();
        $this->assertSame(1, $this->remind([$row])['sent']);
    }

    public function test_exhausted_quota_stops_sending_and_reserves_nothing(): void
    {
        Mail::fake();
        $this->person();
        $this->person('มาลี', 'สุขใจ', 'malee@example.com');
        MailQuota::record(40);

        $result = $this->remind([
            $this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01']),
            $this->row('ร.ท.มาลี สุขใจ', ['licence' => '2026-12-01']),
        ]);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(0, CredentialReminder::query()->count());
        Mail::assertNothingSent();
    }

    public function test_test_mode_sends_only_to_the_test_address_and_reserves_nothing(): void
    {
        Mail::fake();
        $user = $this->person();
        config(['mail.reminder_test_recipient' => 'qa@example.com']);

        $result = $this->remind([$this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01'])]);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, CredentialReminder::query()->count());
        Mail::assertSent(CredentialExpiryMail::class, function (CredentialExpiryMail $mail) use ($user) {
            return $mail->hasTo('qa@example.com')
                && ! $mail->hasTo('somkid@example.com')
                && str_starts_with($mail->envelope()->subject, '[ทดสอบ]')
                && ! str_contains($mail->render(), $user->lastname);
        });
    }

    public function test_invalid_test_address_sends_nothing_at_all(): void
    {
        Mail::fake();
        $this->person();
        config(['mail.reminder_test_recipient' => 'not-an-email']);

        $result = $this->remind([$this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01'])]);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        Mail::assertNothingSent();
    }

    public function test_dry_run_reports_the_plan_but_sends_and_records_nothing(): void
    {
        Mail::fake();
        $this->person();

        $result = $this->remind([$this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01'])], dry: true);

        $this->assertCount(1, $result['plan']);
        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, CredentialReminder::query()->count());
        Mail::assertNothingSent();
    }

    public function test_unmatched_holders_with_due_items_are_reported_as_problems(): void
    {
        Mail::fake();
        $this->person('มาลี', 'สุขใจ', null);

        $result = $this->remind([
            $this->row('น.ต.ไม่มี ในระบบ', ['licence' => '2026-12-01']),
            $this->row('ร.ท.มาลี สุขใจ', ['licence' => '2026-12-01']),
            $this->row('น.ต.ไม่มี อีกคน', ['licence' => '2030-12-01']),
        ]);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(
            [['name' => 'น.ต.ไม่มี ในระบบ', 'reason' => 'ไม่พบบัญชีที่ชื่อตรงกัน'], ['name' => 'ร.ท.มาลี สุขใจ', 'reason' => 'บัญชีไม่มีอีเมลที่ใช้ได้']],
            $result['problems']
        );
    }

    public function test_admin_gets_one_digest_per_run_listing_everyone_reminded_and_problems(): void
    {
        Mail::fake();
        config(['mail.reminder_admin_recipients' => ['boss@example.com']]);
        $this->person();
        $this->person('มาลี', 'สุขใจ', 'malee@example.com');

        $result = $this->remind([
            $this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01']),
            $this->row('ร.ท.มาลี สุขใจ', ['medical' => '2026-11-01']),
            $this->row('ร.ท.ไม่มี ในระบบ', ['rating' => '2026-11-01']),
        ]);

        $this->assertSame(2, $result['sent']);
        $this->assertSame(1, $result['digests']);
        Mail::assertSent(CredentialExpiryMail::class, 2);
        Mail::assertSent(CredentialDigestMail::class, 1);
        Mail::assertSent(CredentialDigestMail::class, function (CredentialDigestMail $mail) {
            $html = $mail->render();

            return $mail->hasTo('boss@example.com')
                && count($mail->people) === 2
                && str_contains($html, 'สมคิด') && str_contains($html, 'มาลี')
                && str_contains($html, 'ไม่มี ในระบบ') && str_contains($html, 'ไม่พบบัญชีที่ชื่อตรงกัน');
        });
    }

    public function test_no_digest_when_nobody_was_reminded(): void
    {
        Mail::fake();
        config(['mail.reminder_admin_recipients' => ['boss@example.com']]);
        $this->person();
        $row = $this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01']);

        $this->remind([$row]);
        $second = $this->remind([$row]);

        $this->assertSame(0, $second['digests']);
        Mail::assertSent(CredentialDigestMail::class, 1);
    }

    public function test_dry_run_sends_no_digest(): void
    {
        Mail::fake();
        config(['mail.reminder_admin_recipients' => ['boss@example.com']]);
        $this->person();

        $this->remind([$this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01'])], dry: true);

        Mail::assertNothingSent();
    }

    public function test_test_mode_digest_goes_to_the_test_address_without_names(): void
    {
        Mail::fake();
        config(['mail.reminder_admin_recipients' => ['boss@example.com'], 'mail.reminder_test_recipient' => 'qa@example.com']);
        $this->person();

        $this->remind([$this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01'])]);

        Mail::assertSent(CredentialDigestMail::class, fn (CredentialDigestMail $mail) => $mail->hasTo('qa@example.com')
            && ! $mail->hasTo('boss@example.com')
            && ! str_contains($mail->render(), 'สมคิด'));
    }

    public function test_invalid_admin_address_is_ignored_and_people_are_still_reminded(): void
    {
        Mail::fake();
        config(['mail.reminder_admin_recipients' => ['not-an-email']]);
        $this->person();

        $result = $this->remind([$this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2026-12-01'])]);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['digests']);
        Mail::assertNotSent(CredentialDigestMail::class);
    }

    public function test_reminds_again_at_60_and_30_days_but_not_in_between(): void
    {
        Mail::fake();
        $this->person();
        $row = $this->row('น.ต.สมคิด สุนทรเพราะ', ['licence' => '2027-01-17']); // หมดอายุ 90 วันจากวันที่ตั้งไว้

        $this->assertSame(1, $this->remind([$row])['sent']); // 90 วัน

        $this->today = $this->today->copy()->addDays(20); // เหลือ 70 วัน
        $this->assertSame(0, $this->remind([$row])['sent']);

        $this->today = $this->today->copy()->addDays(10); // เหลือ 60 วัน
        $this->assertSame(1, $this->remind([$row])['sent']);

        $this->today = $this->today->copy()->addDays(15); // เหลือ 45 วัน
        $this->assertSame(0, $this->remind([$row])['sent']);

        $this->today = $this->today->copy()->addDays(15); // เหลือ 30 วัน
        $this->assertSame(1, $this->remind([$row])['sent']);

        $this->today = $this->today->copy()->addDays(30); // วันหมดอายุ
        $this->assertSame(0, $this->remind([$row])['sent']);

        Mail::assertSent(CredentialExpiryMail::class, 3);
        $this->assertSame(3, CredentialReminder::query()->count());
    }

    public function test_first_seen_late_sends_once_and_marks_earlier_milestones_done(): void
    {
        Mail::fake();
        $this->person();
        $row = $this->row('น.ต.สมคิด สุนทรเพราะ', ['medical' => '2026-11-08']); // เหลือ 20 วัน

        $this->assertSame(1, $this->remind([$row])['sent']);
        $this->assertSame(3, CredentialReminder::query()->count()); // 90, 60, 30

        $this->today = $this->today->copy()->addDays(5);
        $this->assertSame(0, $this->remind([$row])['sent']);
        Mail::assertSent(CredentialExpiryMail::class, 1);
    }

    public function test_failed_send_releases_every_milestone_it_reserved(): void
    {
        $this->person();
        $row = $this->row('น.ต.สมคิด สุนทรเพราะ', ['medical' => '2026-11-08']); // เหลือ 20 วัน

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        $this->assertSame(1, $this->remind([$row])['failed']);
        $this->assertSame(0, CredentialReminder::query()->count());
    }

    public function test_threshold_for(): void
    {
        $this->assertNull(CredentialReminderService::thresholdFor(91));
        $this->assertSame(90, CredentialReminderService::thresholdFor(90));
        $this->assertSame(90, CredentialReminderService::thresholdFor(61));
        $this->assertSame(60, CredentialReminderService::thresholdFor(60));
        $this->assertSame(60, CredentialReminderService::thresholdFor(31));
        $this->assertSame(30, CredentialReminderService::thresholdFor(30));
        $this->assertSame(30, CredentialReminderService::thresholdFor(-10));
    }

    public function test_sheet_failure_propagates_and_sends_nothing(): void
    {
        Mail::fake();
        config(['services.credential_sheet.id' => 'abc']);
        Http::fake(['docs.google.com/*' => Http::response('<html>Sign in</html>')]);

        $this->expectException(CredentialSheetException::class);

        try {
            app(CredentialReminderService::class)->run();
        } finally {
            Mail::assertNothingSent();
            $this->assertSame(0, CredentialReminder::query()->count());
        }
    }
}
