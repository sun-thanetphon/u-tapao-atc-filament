<?php

namespace Tests\Unit;

use App\Support\CaatDocumentType;
use PHPUnit\Framework\TestCase;

class CaatDocumentTypeTest extends TestCase
{
    /** @dataProvider names */
    public function test_classifies_by_title(string $name, ?string $expected): void
    {
        $this->assertSame($expected, CaatDocumentType::classify($name));
    }

    public static function names(): array
    {
        return [
            'draft wins over regulation' => ['ขอเชิญร่วมแสดงความคิดเห็นต่อร่างข้อบังคับ กพท. ฉบับที่ 22', CaatDocumentType::DRAFT],
            'amc gm' => ['ประกาศ กพท. เรื่อง วิธีการที่ยอมรับได้ (AMC/GM) ฉบับที่ 78', CaatDocumentType::AMC_GM],
            'manual' => ['ระเบียบ กพท. ว่าด้วยคู่มือมาตรฐานการบริการ - MOS-ATS', CaatDocumentType::MOS],
            'regulation' => ['ข้อบังคับของสำนักงานการบินพลเรือนแห่งประเทศไทย ฉบับที่ 22', CaatDocumentType::REGULATION],
            'requirement' => ['ข้อกำหนดของสำนักงานการบินพลเรือนแห่งประเทศไทย ฉบับที่ 72', CaatDocumentType::REQUIREMENT],
            'rules' => ['ระเบียบสำนักงานการบินพลเรือนแห่งประเทศไทย ว่าด้วยการเดินอากาศ', CaatDocumentType::RULES],
            'announcement' => ['ประกาศสำนักงานการบินพลเรือนแห่งประเทศไทย เรื่อง ค่าธรรมเนียม', CaatDocumentType::ANNOUNCEMENT],
            'filename style' => ['ข้อบังคับ-กพท.-ฉบับที่-30-ว่าด้วยกฎจราจรทางอากาศ', CaatDocumentType::REGULATION],
            'unknown stays unclassified' => ['Cover-regulation-TCAR-PEL-PART-ATCO.OR_DG_SIGNED', null],
        ];
    }

    public function test_every_type_label_has_a_short_badge_before_the_parenthesis(): void
    {
        foreach (CaatDocumentType::all() as $name) {
            $this->assertMatchesRegularExpression('/^CAAT [^(]+( \(.+\))?$/u', $name);
        }
    }
}
