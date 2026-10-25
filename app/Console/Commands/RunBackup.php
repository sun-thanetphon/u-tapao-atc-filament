<?php

namespace App\Console\Commands;

use App\Support\BackupService;
use App\Support\GoogleDriveBackup;
use Illuminate\Console\Command;

/**
 * สำรองฐานข้อมูลและไฟล์ PDF ลง storage/app/backups
 * ตั้ง cron บนโฮสต์ เช่น ฐานข้อมูลทุกคืน และรวมไฟล์สัปดาห์ละครั้ง:
 *   0 2 * * *  cd ~/domains/u-tapaoatc.com && php artisan backup:run --db-only
 *   30 2 * * 0 cd ~/domains/u-tapaoatc.com && php artisan backup:run
 * ตั้ง GOOGLE_DRIVE_* (ดู backup:drive-auth) เพื่ออัปโหลดไป Google Drive ด้วย หรือ BACKUP_DISK สำหรับ disk อื่น
 * (โฟลเดอร์บน host เดียวกันไม่ช่วยถ้า host เสีย)
 */
class RunBackup extends Command
{
    protected $signature = 'backup:run
        {--db-only : สำรองเฉพาะฐานข้อมูล}
        {--with-videos : รวมวิดีโอสอนที่อัปโหลดไว้ในไฟล์ zip ด้วย (ไฟล์ใหญ่)}
        {--keep=14 : จำนวนชุดสำรองฐานข้อมูลล่าสุดที่เก็บไว้}
        {--keep-files=2 : จำนวนชุดสำรองไฟล์ล่าสุดที่เก็บไว้ (แต่ละชุดใหญ่ระดับ GB)}';

    protected $description = 'สำรองฐานข้อมูลและไฟล์เอกสาร พร้อมลบชุดเก่าเกินกำหนด';

    public function handle(BackupService $backup): int
    {
        $keep = ['db' => (int) $this->option('keep'), 'files' => (int) $this->option('keep-files')];
        $disk = config('filesystems.backup_disk');
        $drive = GoogleDriveBackup::configured() ? new GoogleDriveBackup() : null;
        $made = [];
        $exitCode = self::SUCCESS;

        try {
            $made['db'] = $backup->dumpDatabase();

            if (! $this->option('db-only')) {
                $made['files'] = $backup->zipFiles((bool) $this->option('with-videos'));
            }

            foreach ($made as $kind => $path) {
                $this->info(basename($path) . ' (' . number_format(filesize($path) / 1048576, 2) . ' MB)');

                if ($disk) {
                    $backup->copyTo($disk, $path);
                    $this->info("  คัดลอกไป disk [{$disk}] แล้ว");
                }

                $backup->prune($kind, $keep[$kind]);

                // อัปโหลดล้มเหลวไม่ทำให้ชุดสำรองในเครื่องเสีย แต่ต้องรู้ จึงคืน FAILURE ให้ cron เห็น
                if ($drive) {
                    try {
                        $drive->upload($path);
                        $drive->prune($kind, $keep[$kind]);
                        $this->info('  อัปโหลดไป Google Drive แล้ว');
                    } catch (\Throwable $e) {
                        $this->error('  อัปโหลดไป Google Drive ไม่สำเร็จ: ' . $e->getMessage());
                        $exitCode = self::FAILURE;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->error('สำรองไม่สำเร็จ: ' . $e->getMessage());

            return self::FAILURE;
        }

        return $exitCode;
    }
}
