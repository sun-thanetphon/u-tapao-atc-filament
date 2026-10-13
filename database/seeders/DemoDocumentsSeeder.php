<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\Rank;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * ข้อมูลตัวอย่างสำหรับดูหน้า "เอกสารของฉัน" (เลือกดูตามประเภท) บนเครื่อง local เท่านั้น
 *
 * รัน:  php artisan db:seed --class=DemoDocumentsSeeder
 * ลบ:   php artisan db:seed --class=DemoDocumentsCleanupSeeder
 *
 * บัญชีทดสอบ (ใช้เฉพาะ local):
 *   demo_user  / demo-pass-1234   เจ้าหน้าที่ทั่วไป (แผนกแรกในระบบ)
 *   demo_super / demo-pass-1234   super-admin
 */
class DemoDocumentsSeeder extends Seeder
{
    public const PASSWORD = 'demo-pass-1234';

    public const CODE_PREFIX = 'DEMO-';

    public const CATEGORIES = [
        'DEMO WI (Work Instruction)',
        'DEMO QP (Quality Procedure)',
        'DEMO SD (Supporting Document)',
        'DEMO FM (Form)',
        'DEMO EMP (Emergency Plan)',
        'DEMO LOA (Letter of Agreement)',
        'DEMO NOTAM (Notice)',
        'DEMO ICAO Annex',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('ห้ามรัน seeder ข้อมูลตัวอย่างบน production');

            return;
        }

        $sections = Section::query()->orderBy('id')->pluck('id');
        $mine = (string) $sections[0];
        $other = (string) ($sections[1] ?? '999999');
        $rankId = Rank::query()->value('id');

        $user = $this->account('demo_user', 'user', $sections[0], $rankId);
        $this->account('demo_super', 'super-admin', $sections[0], $rankId);

        $categories = collect(self::CATEGORIES)
            ->map(fn (string $name) => DocumentCategory::firstOrCreate(['name' => $name])->id)
            ->values();

        Storage::disk('local')->put('documents/demo.pdf', $this->pdf());

        // [ประเภท (index หรือ null), ชื่อ, ต้องรับทราบ, รับทราบแล้ว, แผนกที่เห็น]
        $documents = [
            [0, 'WI-DEMO-001 วิธีปฏิบัติบริการจราจรทางอากาศในห้วงเวลาปิดทางวิ่ง', true, false, $mine],
            [0, 'WI-DEMO-002 วิธีปฏิบัติ Low Visibility Operations', false, false, $mine],
            [0, 'WI-DEMO-003 วิธีปฏิบัติบันทึกข้อมูลลงใน Air Traffic Service Log', true, true, $mine],
            [1, 'QP-DEMO-001 การตรวจประเมินภายในด้านนิรภัยการบิน', true, false, $mine],
            [2, 'SD-DEMO-001 ร่างข้อตกลงการปฏิบัติงานระหว่างหน่วยงาน', false, false, $mine],
            [3, 'FM-DEMO-001 แบบฟอร์มใบ DAR (Document Action Request)', false, false, $mine],
            [4, 'QP-DEMO-EMP-001 Aerodrome Control Tower Emergency Plan', true, false, $mine],
            [5, 'LOA-DEMO-001 ข้อตกลงกับหน่วยงานข้างเคียง', false, false, $mine],
            [6, 'NOTAM-DEMO-001 ประกาศปิดทางวิ่งชั่วคราว', true, true, $mine],
            [7, 'ICAO-DEMO Annex 11 Air Traffic Services', false, false, $mine],
            [null, 'ประกาศค่าความสามารถในการให้บริการ (ATC Capacity)', true, false, $mine],
            // อยู่ในแผนกอื่น ผู้ใช้ตัวอย่างต้องไม่เห็นเอกสารนี้
            [1, 'QP-DEMO-HIDDEN เอกสารของแผนกอื่น (ต้องไม่ปรากฏ)', true, false, $other],
        ];

        foreach ($documents as $i => [$categoryIndex, $name, $needsAck, $acknowledged, $viewSection]) {
            $document = Document::withoutEvents(fn () => Document::updateOrCreate(
                ['code' => self::CODE_PREFIX . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT)],
                [
                    'name' => $name,
                    'view_sections' => [$viewSection],
                    'acknowledge_sections' => $needsAck ? [$viewSection] : [],
                    'category_id' => $categoryIndex === null ? null : $categories[$categoryIndex],
                    'file_path' => 'documents/demo.pdf',
                    'publish' => true,
                ],
            ));

            if ($acknowledged) {
                $document->acknowledges()->firstOrCreate(
                    ['user_id' => $user->id],
                    ['acknowledge_date' => now()->subDays($i)],
                );
            }
        }

        $this->command?->info('สร้างข้อมูลตัวอย่างแล้ว: ล็อกอินด้วย demo_user หรือ demo_super (รหัสผ่านดูในไฟล์ seeder นี้)');
    }

    private function account(string $username, string $role, int $sectionId, int $rankId): User
    {
        $user = User::withTrashed()->firstOrNew(['username' => $username]);
        $user->fill([
            'rank_id' => $rankId,
            'section_id' => $sectionId,
            'firstname' => 'Demo',
            'lastname' => $username,
            'password' => bcrypt(self::PASSWORD),
        ]);
        $user->deleted_at = null;
        $user->save();
        $user->syncRoles([$role]);

        return $user;
    }

    /** PDF หน้าเดียวขนาดเล็ก ให้ปุ่ม "อ่านเอกสาร" เปิดได้ */
    private function pdf(): string
    {
        $stream = 'BT /F1 24 Tf 72 760 Td (DEMO DOCUMENT) Tj ET';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
