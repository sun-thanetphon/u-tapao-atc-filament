<?php

namespace App\Support;

use Exception;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\ResetPassword as ResetPasswordNotification;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;

class PasswordResetLink
{
    /**
     * ส่งลิงก์ตั้งรหัสผ่านของ Filament ทันที (notifyNow ไม่เข้าคิว เพราะโฮสต์ไม่มี queue worker)
     * คืนสถานะของ password broker เช่น Password::RESET_LINK_SENT
     */
    public static function send(string $email): string
    {
        return Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            ['email' => $email],
            function (CanResetPassword $user, string $token): void {
                if (! method_exists($user, 'notifyNow')) {
                    throw new Exception('Model [' . $user::class . '] does not have a [notifyNow()] method.');
                }

                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $user);

                $user->notifyNow($notification);
            },
        );
    }
}
