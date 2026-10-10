<?php

namespace App\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SafeMail
{
    /**
     * ส่งอีเมลแบบไม่ให้ล้มทั้งคำขอ (โฮสต์ไม่มี queue) คืน true ถ้าส่งสำเร็จ
     */
    public static function send(string $to, Mailable $mail): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (Throwable $e) {
            Log::warning('Failed to send mail', [
                'mailable' => $mail::class,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
