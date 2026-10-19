<?php

namespace Tests\Feature;

use App\Enums\RoleEnum;
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
        config(['mail.reminder_test_recipient' => null, 'mail.daily_cap_for_reminders' => 40]);
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
        $this->assertSame(3, CredentialReminder::query()->count());
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
