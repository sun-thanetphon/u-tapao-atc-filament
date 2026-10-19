<?php

namespace App\Console\Commands;

use App\Support\CredentialReminderService;
use App\Support\CredentialSheet;
use App\Support\CredentialSheetException;
use Illuminate\Console\Command;

/**
 * ส่งอีเมลเตือนวันหมดอายุใบอนุญาต/ใบรับรองแพทย์/rating/ภาษา เมื่อเหลือไม่เกิน 90 วัน
 * ตั้ง cron รายวันบนโฮสต์ให้เรียกคำสั่งนี้
 */
class RemindCredentials extends Command
{
    protected $signature = 'credentials:remind {--dry-run : แสดงว่าใครจะได้เมลรายการไหน โดยไม่ส่งและไม่บันทึก}';

    protected $description = 'ส่งอีเมลเตือนวันหมดอายุใบอนุญาตของผู้ควบคุมการจราจรทางอากาศ (ก่อนหมดอายุ 3 เดือน)';

    public function handle(CredentialReminderService $service): int
    {
        $dry = (bool) $this->option('dry-run');

        try {
            $result = $service->run($dry);
        } catch (CredentialSheetException $e) {
            // ข้อความ exception ไม่มีข้อมูลส่วนบุคคล (มีแค่สาเหตุที่อ่านชีตไม่ได้)
            $this->error('อ่านชีตไม่สำเร็จ: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($dry) {
            $this->table(
                ['ชื่อในชีต', 'รายการ', 'หมดอายุ', 'เหลือ (วัน)'],
                collect($result['plan'])->flatMap(fn (array $person) => array_map(fn (array $item) => [
                    $person['name'],
                    CredentialSheet::KIND_LABELS[$item['kind']],
                    $item['expires_on'],
                    $item['days_left'],
                ], $person['items']))->all()
            );

            foreach ($result['problems'] as $problem) {
                $this->warn("ส่งไม่ได้: {$problem['name']} ({$problem['reason']})");
            }

            $this->info('[dry-run] จะส่ง ' . count($result['plan']) . ' คน ไม่ได้ส่งและไม่ได้บันทึกอะไร');

            return self::SUCCESS;
        }

        $this->info("ส่งแล้ว {$result['sent']} ฉบับ, ข้าม {$result['skipped']}, ล้มเหลว {$result['failed']}, ส่งไม่ได้ " . count($result['problems']) . ' คน');

        return self::SUCCESS;
    }
}
