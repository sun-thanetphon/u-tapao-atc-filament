<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * ขอ refresh token ของ Google Drive ด้วย device flow (รันบนเครื่องไหนก็ได้ที่ตั้ง CLIENT_ID/SECRET แล้ว)
 * แล้วนำค่าที่ได้ไปใส่ GOOGLE_DRIVE_REFRESH_TOKEN ใน .env ของ production
 */
class BackupDriveAuth extends Command
{
    protected $signature = 'backup:drive-auth';

    protected $description = 'ขอสิทธิ์ Google Drive (drive.file) สำหรับอัปโหลดชุดสำรอง และแสดง refresh token';

    public function handle(): int
    {
        $id = config('services.google_drive.client_id');
        $secret = config('services.google_drive.client_secret');

        if (blank($id) || blank($secret)) {
            $this->error('ตั้ง GOOGLE_DRIVE_CLIENT_ID และ GOOGLE_DRIVE_CLIENT_SECRET ใน .env ก่อน');

            return self::FAILURE;
        }

        $device = Http::asForm()->post('https://oauth2.googleapis.com/device/code', [
            'client_id' => $id,
            'scope' => 'https://www.googleapis.com/auth/drive.file',
        ]);

        if (! $device->successful()) {
            $this->error('ขอรหัสไม่สำเร็จ: ' . $device->json('error_description', $device->status()));

            return self::FAILURE;
        }

        $this->info('เปิด ' . $device['verification_url'] . ' แล้วใส่รหัส ' . $device['user_code']);

        $deadline = time() + (int) $device['expires_in'];
        $interval = (int) $device['interval'];

        while (time() < $deadline) {
            sleep($interval);

            $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $id,
                'client_secret' => $secret,
                'device_code' => $device['device_code'],
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ]);

            if ($token->successful()) {
                $this->info('สำเร็จ ใส่ค่านี้ใน .env:');
                $this->line('GOOGLE_DRIVE_REFRESH_TOKEN=' . $token['refresh_token']);

                return self::SUCCESS;
            }

            match ($token->json('error')) {
                'authorization_pending' => null,
                'slow_down' => $interval += 5,
                default => throw new \RuntimeException('ไม่ได้รับอนุญาต: ' . $token->json('error')),
            };
        }

        $this->error('รหัสหมดอายุ รันคำสั่งใหม่');

        return self::FAILURE;
    }
}
