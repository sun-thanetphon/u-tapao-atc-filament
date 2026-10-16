<?php

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Command;

/**
 * ล้างรหัส HTML entity ที่ปนอยู่ในชื่อเอกสาร (เช่น &#x274c;) ใช้กับเอกสารที่นำเข้าจากเว็บ กพท.
 */
class CleanDocumentNames extends Command
{
    protected $signature = 'documents:clean-names {--dry-run : แสดงชื่อก่อน/หลังโดยไม่บันทึก}';

    protected $description = 'ตัดรหัส HTML entity ที่ปนมาในชื่อเอกสารออก';

    public function handle(): int
    {
        $changed = 0;

        Document::withTrashed()->where('name', 'like', '%&#%')->each(function (Document $document) use (&$changed) {
            $clean = ImportRegulations::cleanName($document->name);

            if ($clean === $document->name) {
                return;
            }

            $this->line("#{$document->id}: {$document->name}\n  => {$clean}");

            if (! $this->option('dry-run')) {
                // บันทึกเฉพาะชื่อ ไม่แตะไฟล์/รอบรับทราบ (observer ดูแค่ file_path)
                $document->forceFill(['name' => $clean])->save();
            }
            $changed++;
        });

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '') . "แก้ {$changed} ชื่อ");

        return self::SUCCESS;
    }
}
