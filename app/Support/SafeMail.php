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
            MailQuota::record();

            return true;
        } catch (Throwable $e) {
            // ไม่บันทึกข้อความของ exception: ข้อความจาก transport อาจมีอีเมลผู้รับปนอยู่
            Log::warning('Failed to send mail', array_filter([
                'mailable' => $mail::class,
                'exception' => $e::class,
                'code' => $e->getCode(),
            ]));

            return false;
        }
    }
}
