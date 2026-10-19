<?php

namespace App\Support;

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

    public const PROBLEM_REASONS = [
        CredentialMatcher::NO_ACCOUNT => 'ไม่พบบัญชีที่ชื่อตรงกัน',
        CredentialMatcher::DUPLICATE => 'มีบัญชีชื่อซ้ำกัน จึงไม่ส่ง',
        CredentialMatcher::NO_EMAIL => 'บัญชีไม่มีอีเมลที่ใช้ได้',
        'unreadable' => 'อ่านวันที่ในชีตไม่ได้',
    ];

    /**
     * @param  list<array{name: string, items: array<string, Carbon>, unreadable: list<string>}>|null  $rows  ไม่ระบุ = ดึงจากชีต
     * @return array{sent: int, skipped: int, failed: int, plan: list<array{user_id: int, name: string, items: list<array{kind: string, expires_on: string, days_left: int}>}>, problems: list<array{name: string, reason: string}>}
     *
     * @throws CredentialSheetException
     */
    public function run(bool $dryRun = false, ?array $rows = null, ?Carbon $today = null): array
    {
        $rows ??= CredentialSheet::fetch();
        $today = ($today ?? now())->copy()->timezone(config('app.timezone'))->startOfDay();

        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'plan' => [], 'problems' => []];

        $testRecipient = trim((string) config('mail.reminder_test_recipient'));

        // ตั้งที่อยู่ทดสอบไว้แต่ไม่ถูกต้อง: ห้ามส่งให้ใครเลย (ห้ามถอยไปส่งผู้ใช้จริง)
        $sendingBlocked = ! $dryRun && $testRecipient !== '' && ! filter_var($testRecipient, FILTER_VALIDATE_EMAIL);

        if ($sendingBlocked) {
            Log::warning('Credential reminders skipped: MAIL_REMINDER_TEST_RECIPIENT is invalid');
        }

        $matcher = new CredentialMatcher();
        $reminded = CredentialReminder::query()
            ->get(['user_id', 'item', 'expires_on'])
            ->mapWithKeys(fn (CredentialReminder $r) => [$r->user_id . '|' . $r->item . '|' . $r->expires_on->toDateString() => true])
            ->all();

        $cap = (int) config('mail.daily_cap_for_reminders', 40);
        $quotaLeft = true;

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
                $ok ? $result['sent']++ : $result['failed']++;

                continue;
            }

            // จองสิทธิ์ก่อนส่ง: รัน cron ซ้อนกันแล้วอีกรอบจะจองไม่ได้ จึงไม่ส่งซ้ำ
            $reserved = array_values(array_filter($items, fn (array $item) => DB::table('credential_reminders')->insertOrIgnore([
                'user_id' => $user->id,
                'item' => $item['kind'],
                'expires_on' => $item['expires_on'],
                'created_at' => now(),
            ]) === 1));

            if ($reserved === []) {
                $result['skipped']++;

                continue;
            }

            if ($this->send((string) $user->email, new CredentialExpiryMail($user, $reserved))) {
                $result['sent']++;

                continue;
            }

            // ส่งไม่สำเร็จ: คืนสิทธิ์ เพื่อให้ลองใหม่พรุ่งนี้
            foreach ($reserved as $item) {
                CredentialReminder::query()
                    ->where('user_id', $user->id)
                    ->where('item', $item['kind'])
                    ->whereDate('expires_on', $item['expires_on'])
                    ->delete();
            }

            $result['failed']++;
        }

        // ไม่บันทึกชื่อ อีเมล หรือวันที่ใน log
        Log::info('Credential reminders run', [
            'dry_run' => $dryRun,
            'sent' => $result['sent'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'problems' => count($result['problems']),
        ]);

        return $result;
    }

    /**
     * @param  array<string, Carbon>  $items
     * @param  array<string, true>  $reminded
     * @return list<array{kind: string, expires_on: string, days_left: int}>
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

            if ($daysLeft > self::WINDOW_DAYS) {
                continue;
            }

            if ($userId !== null && isset($reminded[$userId . '|' . $kind . '|' . $expires->toDateString()])) {
                continue;
            }

            $due[] = ['kind' => $kind, 'expires_on' => $expires->toDateString(), 'days_left' => $daysLeft];
        }

        return $due;
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
