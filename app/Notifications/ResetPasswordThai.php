<?php

namespace App\Notifications;

use Filament\Notifications\Auth\ResetPassword as FilamentResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * อีเมลลิงก์ตั้งรหัสผ่านใหม่ภาษาไทย (ใช้แทนข้อความอังกฤษเริ่มต้นของ Filament)
 * ส่งผ่าน notifyNow เสมอ เพราะโฮสต์ไม่มี queue worker
 */
class ResetPasswordThai extends FilamentResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('ตั้งรหัสผ่านใหม่ - ' . config('app.name'))
            ->markdown('mail.password-reset', [
                'url' => $this->resetUrl($notifiable),
                'minutes' => (int) config('auth.passwords.' . config('auth.defaults.passwords') . '.expire'),
            ]);
    }
}
