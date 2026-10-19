<?php

namespace App\Filament\Pages;

use App\Enums\RoleEnum;
use App\Mail\CredentialExpiryMail;
use App\Models\CredentialReminder;
use App\Support\CredentialMatcher;
use App\Support\CredentialReminderService;
use App\Support\CredentialSheet;
use App\Support\CredentialSheetException;
use App\Support\MailQuota;
use App\Support\SafeMail;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ดูวันหมดอายุใบอนุญาต/ใบรับรองแพทย์/rating/ภาษา จากชีต (อ่านอย่างเดียว แก้ที่ชีต)
 * เห็นเฉพาะ super-admin และ admin เพราะมีข้อมูลสุขภาพ
 */
class CredentialExpiry extends Page
{
    private const CACHE_KEY = 'credential-sheet-rows';

    private const CACHE_SECONDS = 600;

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationGroup = 'Accounts';

    protected static ?string $navigationLabel = 'วันหมดอายุใบอนุญาต';

    protected static ?string $title = 'วันหมดอายุใบอนุญาต';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.credential-expiry';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([RoleEnum::SUPERADMIN, RoleEnum::ADMIN]);
    }

    /**
     * @return array{error: ?string, items: list<array<string, mixed>>, problems: list<array{name: string, reason: string}>}
     */
    protected function getViewData(): array
    {
        try {
            $rows = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => CredentialSheet::fetch());
        } catch (CredentialSheetException $e) {
            return ['error' => $e->getMessage(), 'items' => [], 'problems' => []];
        }

        $matcher = new CredentialMatcher();
        $reminded = CredentialReminder::query()
            ->get(['user_id', 'item', 'expires_on', 'threshold'])
            ->mapWithKeys(fn (CredentialReminder $r) => [CredentialReminderService::key($r->user_id, $r->item, $r->expires_on->toDateString(), $r->threshold) => true])
            ->all();

        $today = now()->timezone(config('app.timezone'))->startOfDay();
        $items = [];
        $problems = [];

        foreach ($rows as $row) {
            $match = $matcher->match($row['name']);

            if ($match['status'] !== CredentialMatcher::OK) {
                $problems[] = ['name' => $row['name'], 'reason' => CredentialReminderService::PROBLEM_REASONS[$match['status']]];
            }

            foreach ($row['unreadable'] as $kind) {
                $problems[] = ['name' => $row['name'], 'reason' => CredentialReminderService::PROBLEM_REASONS['unreadable'] . ': ' . CredentialSheet::KIND_LABELS[$kind]];
            }

            foreach ($row['items'] as $kind => $date) {
                $expires = $date->copy()->startOfDay();
                $daysLeft = (int) round($today->floatDiffInDays($expires, false));
                $threshold = CredentialReminderService::thresholdFor($daysLeft);
                $sent = $threshold !== null && $match['user']
                    && isset($reminded[CredentialReminderService::key($match['user']->id, $kind, $expires->toDateString(), $threshold)]);

                $items[] = [
                    'name' => $row['name'],
                    'kind' => CredentialSheet::KIND_LABELS[$kind],
                    'expires_on' => $expires->format('d/m/Y'),
                    'days_left' => $daysLeft,
                    'state' => match (true) {
                        $threshold === null => ['label' => 'ยังไม่ถึงกำหนดเตือน', 'color' => 'gray'],
                        $match['status'] !== CredentialMatcher::OK => ['label' => 'ส่งไม่ได้: ' . CredentialReminderService::PROBLEM_REASONS[$match['status']], 'color' => 'danger'],
                        $sent => ['label' => 'เตือนแล้ว', 'color' => 'success'],
                        default => ['label' => 'จะเตือนในรอบถัดไป', 'color' => 'warning'],
                    },
                ];
            }
        }

        usort($items, fn (array $a, array $b) => $a['days_left'] <=> $b['days_left']);

        return ['error' => null, 'items' => $items, 'problems' => $problems];
    }

    public function refreshSheet(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * ส่งอีเมลตัวอย่างถึงผู้กดเอง (ไม่จองสิทธิ์ ไม่เกี่ยวกับรายการจริง)
     */
    public function sendTest(): void
    {
        abort_unless(static::canAccess(), 403);

        $user = auth()->user();
        $email = trim((string) $user->email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Notification::make()->title('บัญชีของคุณยังไม่มีอีเมล จึงส่งตัวอย่างไม่ได้')->warning()->send();

            return;
        }

        if (! MailQuota::canUse(1, (int) config('mail.daily_cap_for_reminders', 40))) {
            Notification::make()->title('โควตาอีเมลวันนี้เหลือไม่พอ ลองใหม่พรุ่งนี้')->warning()->send();

            return;
        }

        $sample = [
            ['kind' => 'medical', 'expires_on' => now()->addDays(80)->toDateString(), 'days_left' => 80],
            ['kind' => 'licence', 'expires_on' => now()->addDays(30)->toDateString(), 'days_left' => 30],
            ['kind' => 'rating', 'expires_on' => now()->subDays(5)->toDateString(), 'days_left' => -5],
        ];

        try {
            $sent = SafeMail::send($email, new CredentialExpiryMail($user, $sample, true));
        } catch (Throwable $e) {
            Log::error('Credential test mail crashed', ['exception' => $e::class]);
            $sent = false;
        }

        $sent
            ? Notification::make()->title('ส่งอีเมลตัวอย่างไปที่ ' . $email . ' แล้ว')->success()->send()
            : Notification::make()->title('ส่งอีเมลตัวอย่างไม่สำเร็จ')->danger()->send();
    }
}
