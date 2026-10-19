<?php

namespace App\Listeners;

use App\Models\LoginLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

class LoginLogSubscriber
{
    /** เก็บประวัติไว้กี่วัน */
    private const RETENTION_DAYS = 90;

    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
        ];
    }

    public function onLogin(Login $event): void
    {
        $user = $event->user;

        // CustomLogin เช็กสถานะหลังรหัสผ่านถูก แล้ว logout ทันที: นับเป็นเข้าไม่สำเร็จ
        if ($user instanceof User && ! $user->isActive()) {
            $this->record(LoginLog::EVENT_FAILED, $user->getAuthIdentifier(), $user->username);

            return;
        }

        $this->record(LoginLog::EVENT_LOGIN, $user->getAuthIdentifier(), $user->username ?? null);

        if ($user instanceof User) {
            // ไม่แตะ updated_at และไม่ยิง model event
            User::withoutTimestamps(fn () => $user->forceFill(['last_login_at' => now()])->saveQuietly());
        }

        // ไม่มี cron บน shared host: ล้างของเก่าเป็นครั้งคราวตอนมีคนล็อกอิน
        if (random_int(1, 50) === 1) {
            $this->safely(fn () => LoginLog::query()
                ->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))
                ->delete());
        }
    }

    public function onLogout(Logout $event): void
    {
        $user = $event->user;

        // logout ที่ตามหลัง login ที่ถูกปฏิเสธ (สถานะไม่ active) ไม่บันทึก
        if ($user && ! ($user instanceof User && ! $user->isActive())) {
            $this->record(LoginLog::EVENT_LOGOUT, $event->user->getAuthIdentifier(), $event->user->username ?? null);
        }
    }

    public function onFailed(Failed $event): void
    {
        // เก็บเฉพาะ username ที่พิมพ์ ไม่เก็บรหัสผ่าน
        $username = $event->credentials['username'] ?? $event->credentials['email'] ?? null;

        $this->record(LoginLog::EVENT_FAILED, $event->user?->getAuthIdentifier(), is_string($username) ? $username : null);
    }

    private function record(string $type, mixed $userId, ?string $username): void
    {
        $this->safely(fn () => LoginLog::create([
            'user_id' => $userId,
            'username' => $username !== null ? mb_substr($username, 0, 255) : null,
            'event' => $type,
            'ip_address' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
        ]));
    }

    /** บันทึกประวัติพลาดต้องไม่ทำให้ล็อกอินพัง */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('Login log failed', ['error' => $e->getMessage()]);
        }
    }
}
