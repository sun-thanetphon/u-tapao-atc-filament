<?php

namespace App\Providers\Filament\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Exception;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Notifications\Auth\ResetPassword as ResetPasswordNotification;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\PasswordReset\RequestPasswordReset;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

class CustomRequestPasswordReset extends RequestPasswordReset
{
    protected static string $layout = 'filament.layouts.login';

    public function hasLogo(): bool
    {
        // โลโก้แสดงอยู่ในแผงภาพด้านซ้ายแล้ว
        return false;
    }

    public function getHeading(): string | Htmlable
    {
        return 'ลืมรหัสผ่าน';
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->helperText('ไม่พบอีเมล? ตรวจโฟลเดอร์สแปม');
    }

    public function request(): void
    {
        try {
            // 3 ครั้งต่อชั่วโมงต่อ IP (ค่าเริ่มต้นของ Filament คือ 2 ครั้งต่อนาที)
            $this->rateLimit(3, 3600);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        $this->sendResetLinkIfEligible(trim((string) ($data['email'] ?? '')));

        // ตอบเหมือนกันทุกกรณี เพื่อไม่ให้เดาได้ว่าอีเมลมีอยู่ในระบบหรือไม่
        $this->sendNeutralNotification();

        $this->form->fill();
    }

    /**
     * ส่งลิงก์เฉพาะบัญชีที่มีอยู่และอนุมัติแล้ว ไม่ว่าจะเกิดอะไรขึ้นก็ไม่โยนข้อผิดพลาดออกไป
     */
    protected function sendResetLinkIfEligible(string $email): void
    {
        if ($email === '') {
            return;
        }

        // SoftDeletes scope ตัดบัญชีที่ถูกลบออกให้แล้ว
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! $user->isActive()) {
            return;
        }

        try {
            Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
                ['email' => $email],
                function (CanResetPassword $user, string $token): void {
                    if (! method_exists($user, 'notify')) {
                        throw new Exception('Model [' . $user::class . '] does not have a [notify()] method.');
                    }

                    $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                    $notification->url = Filament::getResetPasswordUrl($token, $user);

                    $user->notify($notification);
                },
            );
        } catch (Throwable $e) {
            Log::warning('Failed to send password reset link', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sendNeutralNotification(): void
    {
        Notification::make()
            ->title('หากอีเมลนี้มีอยู่ในระบบ เราได้ส่งลิงก์ตั้งรหัสผ่านใหม่ให้แล้ว')
            ->body('ลิงก์ใช้ได้ 60 นาที หากไม่พบอีเมล ให้ตรวจโฟลเดอร์สแปม')
            ->success()
            ->send();
    }
}
