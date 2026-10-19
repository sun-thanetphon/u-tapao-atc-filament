<?php

namespace App\Support;

use App\Mail\CredentialDigestMail;
use App\Mail\CredentialExpiryMail;
use App\Models\CredentialReminder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ตัดสินว่าใครต้องได้เมลเตือนวันหมดอายุ (เหลือ <= 90 วัน) ส่ง และจำว่าเตือนแล้ว
 * ผู้ใช้หนึ่งคนได้เมลฉบับเดียวต่อรอบ รวมทุกรายการที่ยังไม่เคยเตือน
 */
class CredentialReminderService
{
    public const WINDOW_DAYS = 90;

    /** เตือนที่เหลือ 3 เดือน, 2 เดือน, 1 เดือน (วัน) เรียงมากไปน้อย */
    public const THRESHOLDS = [90, 60, 30];

    /**
     * เกณฑ์ที่แคบที่สุดที่ถึงแล้ว เช่น เหลือ 50 วัน = 60, เหลือ 20 วัน (หรือหมดอายุแล้ว) = 30
     * null = ยังไม่ถึงเกณฑ์แรก
     */
    public static function thresholdFor(int $daysLeft): ?int
    {
        $reached = array_filter(self::THRESHOLDS, fn (int $t) => $daysLeft <= $t);

        return $reached === [] ? null : min($reached);
    }

    public const PROBLEM_REASONS = [
        CredentialMatcher::NO_ACCOUNT => 'ไม่พบบัญชีที่ชื่อตรงกัน',
        CredentialMatcher::DUPLICATE => 'มีบัญชีชื่อซ้ำกัน จึงไม่ส่ง',
        CredentialMatcher::NO_EMAIL => 'บัญชีไม่มีอีเมลที่ใช้ได้',
        'unreadable' => 'อ่านวันที่ในชีตไม่ได้',
    ];

    /**
     * @param  list<array{name: string, items: array<string, Carbon>, unreadable: list<string>}>|null  $rows  ไม่ระบุ = ดึงจากชีต
     * @return array{sent: int, skipped: int, failed: int, digests: int, plan: list<array{user_id: int, name: string, items: list<array{kind: string, expires_on: string, days_left: int}>}>, problems: list<array{name: string, reason: string}>}
     *
     * @throws CredentialSheetException
     */
    public function run(bool $dryRun = false, ?array $rows = null, ?Carbon $today = null): array
    {
        $rows ??= CredentialSheet::fetch();
        $today = ($today ?? now())->copy()->timezone(config('app.timezone'))->startOfDay();

        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'digests' => 0, 'plan' => [], 'problems' => []];

        $testRecipient = trim((string) config('mail.reminder_test_recipient'));

        // ตั้งที่อยู่ทดสอบไว้แต่ไม่ถูกต้อง: ห้ามส่งให้ใครเลย (ห้ามถอยไปส่งผู้ใช้จริง)
        $sendingBlocked = ! $dryRun && $testRecipient !== '' && ! filter_var($testRecipient, FILTER_VALIDATE_EMAIL);

        if ($sendingBlocked) {
            Log::warning('Credential reminders skipped: MAIL_REMINDER_TEST_RECIPIENT is invalid');
        }

        $matcher = new CredentialMatcher();
        $reminded = CredentialReminder::query()
            ->get(['user_id', 'item', 'expires_on', 'threshold'])
            ->mapWithKeys(fn (CredentialReminder $r) => [self::key($r->user_id, $r->item, $r->expires_on->toDateString(), $r->threshold) => true])
            ->all();

        $cap = (int) config('mail.daily_cap_for_reminders', 40);
        $quotaLeft = true;
        $remindedPeople = [];

        foreach ($rows as $row) {
            if ($row['unreadable'] !== []) {
                $result['problems'][] = ['name' => $row['name'], 'reason' => self::PROBLEM_REASONS['unreadable']];
            }

            $match = $matcher->match($row['name']);

            if ($match['status'] !== CredentialMatcher::OK) {
                // ไม่มีรายการใกล้หมดอายุก็ไม่ต้องรบกวนหน้า admin ด้วยปัญหาของคนนั้น
                if ($this->dueItems($row['items'], [], $today, null) !== []) {
                    $result['problems'][] = ['name' => $row['name'], 'reason' => self::PROBLEM_REASONS[$match['status']]];
                }

                continue;
            }

            $user = $match['user'];
            $items = $this->dueItems($row['items'], $reminded, $today, $user->id);

            if ($items === []) {
                continue;
            }

            $result['plan'][] = ['user_id' => $user->id, 'name' => $row['name'], 'items' => $items];

            if ($dryRun) {
                continue;
            }

            if ($sendingBlocked || ! $quotaLeft || ! ($quotaLeft = MailQuota::canUse(1, $cap))) {
                $result['skipped']++;

                continue;
            }

            if ($testRecipient !== '') {
                $ok = $this->send($testRecipient, new CredentialExpiryMail($user, $items, true));
                if ($ok) {
                    $result['sent']++;
                    $remindedPeople[] = ['name' => $row['name'], 'items' => $items];
                } else {
                    $result['failed']++;
                }

                continue;
            }

            // จองสิทธิ์ก่อนส่ง: รัน cron ซ้อนกันแล้วอีกรอบจะจองไม่ได้ จึงไม่ส่งซ้ำ
            // จองเกณฑ์ที่ถึงแล้วและเกณฑ์ที่กว้างกว่าทั้งหมด (เช่น เห็นครั้งแรกตอนเหลือ 20 วัน = ถือว่า 90/60/30 เตือนแล้ว)
            $reserved = [];
            $inserted = [];

            foreach ($items as $item) {
                $newly = [];

                foreach (self::THRESHOLDS as $t) {
                    if ($t >= $item['threshold'] && DB::table('credential_reminders')->insertOrIgnore([
                        'user_id' => $user->id,
                        'item' => $item['kind'],
                        'expires_on' => $item['expires_on'],
                        'threshold' => $t,
                        'created_at' => now(),
                    ]) === 1) {
                        $newly[] = $t;
                    }
                }

                if (in_array($item['threshold'], $newly, true)) {
                    $reserved[] = $item;
                    $inserted[$item['kind']] = $item['expires_on'] . '|' . implode(',', $newly);
                } else {
                    $this->release($user->id, $item['kind'], $item['expires_on'], $newly);
                }
            }

            if ($reserved === []) {
                $result['skipped']++;

                continue;
            }

            if ($this->send((string) $user->email, new CredentialExpiryMail($user, $reserved))) {
                $result['sent']++;
                $remindedPeople[] = ['name' => $row['name'], 'items' => $reserved];

                continue;
            }

            // ส่งไม่สำเร็จ: คืนสิทธิ์ เพื่อให้ลองใหม่พรุ่งนี้
            foreach ($reserved as $item) {
                [$date, $thresholds] = explode('|', $inserted[$item['kind']]);
                $this->release($user->id, $item['kind'], $date, array_map('intval', explode(',', $thresholds)));
            }

            $result['failed']++;
        }

        $result['digests'] = $this->sendDigest($remindedPeople, $result['problems'], $testRecipient);

        // ไม่บันทึกชื่อ อีเมล หรือวันที่ใน log
        Log::info('Credential reminders run', [
            'dry_run' => $dryRun,
            'sent' => $result['sent'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'digests' => $result['digests'],
            'problems' => count($result['problems']),
        ]);

        return $result;
    }

    /**
     * เมลสรุปถึงผู้ดูแลทุกครั้งที่มีการเตือนจริง (ส่งไม่เกินหนึ่งฉบับต่อผู้ดูแลต่อรอบ)
     * โหมดทดสอบส่งไปที่อยู่ทดสอบอย่างเดียว ไม่แสดงชื่อจริง ผู้ดูแลที่ตั้งไว้ไม่ถูกต้องข้ามไป
     *
     * @param  list<array{name: string, items: list<array{kind: string, expires_on: string, days_left: int}>}>  $people
     * @param  list<array{name: string, reason: string}>  $problems
     */
    private function sendDigest(array $people, array $problems, string $testRecipient): int
    {
        if ($people === []) {
            return 0;
        }

        $test = $testRecipient !== '';
        $recipients = $test ? [$testRecipient] : (array) config('mail.reminder_admin_recipients', []);
        $cap = (int) config('mail.daily_cap_for_reminders', 40);
        $sent = 0;

        foreach (array_unique($recipients) as $to) {
            if (! filter_var($to, FILTER_VALIDATE_EMAIL) || ! MailQuota::canUse(1, $cap)) {
                continue;
            }

            try {
                SafeMail::send($to, new CredentialDigestMail($people, $problems, $test)) && $sent++;
            } catch (Throwable $e) {
                Log::error('Credential digest crashed while sending', ['exception' => $e::class]);
            }
        }

        return $sent;
    }

    /**
     * @param  array<string, Carbon>  $items
     * @param  array<string, true>  $reminded
     * @return list<array{kind: string, expires_on: string, days_left: int, threshold: int}>
     */
    private function dueItems(array $items, array $reminded, Carbon $today, ?int $userId): array
    {
        $due = [];

        foreach (CredentialSheet::KINDS as $kind) {
            if (! isset($items[$kind])) {
                continue;
            }

            $expires = $items[$kind]->copy()->startOfDay();
            $daysLeft = (int) round($today->floatDiffInDays($expires, false));
            $threshold = self::thresholdFor($daysLeft);

            if ($threshold === null) {
                continue;
            }

            if ($userId !== null && isset($reminded[self::key($userId, $kind, $expires->toDateString(), $threshold)])) {
                continue;
            }

            $due[] = ['kind' => $kind, 'expires_on' => $expires->toDateString(), 'days_left' => $daysLeft, 'threshold' => $threshold];
        }

        return $due;
    }

    public static function key(int $userId, string $kind, string $expiresOn, int $threshold): string
    {
        return $userId . '|' . $kind . '|' . $expiresOn . '|' . $threshold;
    }

    /**
     * @param  list<int>  $thresholds
     */
    private function release(int $userId, string $kind, string $expiresOn, array $thresholds): void
    {
        if ($thresholds === []) {
            return;
        }

        CredentialReminder::query()
            ->where('user_id', $userId)
            ->where('item', $kind)
            ->whereDate('expires_on', $expiresOn)
            ->whereIn('threshold', $thresholds)
            ->delete();
    }

    private function send(string $to, CredentialExpiryMail $mail): bool
    {
        try {
            return SafeMail::send($to, $mail);
        } catch (Throwable $e) {
            Log::error('Credential reminder crashed while sending', ['exception' => $e::class]);

            return false;
        }
    }
}
