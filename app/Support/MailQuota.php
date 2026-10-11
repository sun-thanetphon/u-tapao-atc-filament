<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * นับจำนวนอีเมลที่ส่งออกจากกล่องเดียวกันในแต่ละวัน (โฮสต์จำกัด 100 ฉบับต่อ 24 ชั่วโมง)
 * ใช้ร่วมกับอีเมลรีเซ็ตรหัสผ่านและอีเมลลงทะเบียน จึงต้องนับทุกฉบับ
 */
class MailQuota
{
    public static function used(): int
    {
        try {
            return (int) Cache::get(self::key(), 0);
        } catch (Throwable) {
            return 0;
        }
    }

    public static function record(int $messages = 1): void
    {
        if ($messages < 1) {
            return;
        }

        // ตัวนับต้องไม่ทำให้การส่งเมลล้ม
        try {
            $key = self::key();

            Cache::add($key, 0, now()->addHours(26));
            Cache::increment($key, $messages);
        } catch (Throwable) {
            //
        }
    }

    public static function canUse(int $messages, int $cap): bool
    {
        // ตรวจโควตาไม่ได้ให้ถือว่าไม่เหลือ (ปลอดภัยกว่าส่งเกิน)
        try {
            return (int) Cache::get(self::key(), 0) + $messages <= $cap;
        } catch (Throwable) {
            return false;
        }
    }

    private static function key(): string
    {
        return 'mail-quota:' . now()->timezone(config('app.timezone'))->format('Y-m-d');
    }
}
