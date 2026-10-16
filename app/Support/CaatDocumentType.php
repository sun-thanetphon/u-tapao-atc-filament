<?php

namespace App\Support;

/**
 * แยกประเภทเอกสารที่นำเข้าจากเว็บ กพท. จากชื่อเรื่อง คืนชื่อประเภท (ป้ายคือข้อความก่อนวงเล็บ) หรือ null ถ้าจัดไม่ได้
 * ลำดับสำคัญ: ร่างมาก่อน (ชื่อร่างมักมีคำว่าข้อบังคับ/ระเบียบอยู่ด้วย) แล้ว AMC/GM และคู่มือ ก่อนประเภทตามหัวเรื่อง
 */
class CaatDocumentType
{
    public const DRAFT = 'CAAT Draft (ร่าง/รับฟังความเห็น)';

    public const AMC_GM = 'CAAT AMC/GM (วิธีการที่ยอมรับได้)';

    public const MOS = 'CAAT MOS (คู่มือมาตรฐาน)';

    public const REGULATION = 'CAAT Regulation (ข้อบังคับ)';

    public const REQUIREMENT = 'CAAT Requirement (ข้อกำหนด)';

    public const RULES = 'CAAT Rules (ระเบียบ)';

    public const ANNOUNCEMENT = 'CAAT Announcement (ประกาศ)';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [self::REGULATION, self::REQUIREMENT, self::RULES, self::ANNOUNCEMENT, self::AMC_GM, self::MOS, self::DRAFT];
    }

    public static function classify(string $name): ?string
    {
        return match (true) {
            (bool) preg_match('/ร่าง|รับฟังความ|แสดงความคิดเห็น|Draft/iu', $name) => self::DRAFT,
            (bool) preg_match('/AMC|วิธีการที่ยอมรับได้|Guidance Material|แนวทางปฏิบัติ|คำแนะนำ/iu', $name) => self::AMC_GM,
            (bool) preg_match('/คู่มือ|MOS|Manual/iu', $name) => self::MOS,
            (bool) preg_match('/^ข้อบังคับ/u', $name) => self::REGULATION,
            (bool) preg_match('/^ข้อกำหนด/u', $name) => self::REQUIREMENT,
            (bool) preg_match('/^ระเบียบ/u', $name) => self::RULES,
            (bool) preg_match('/^ประกาศ|^Announcement/iu', $name) => self::ANNOUNCEMENT,
            default => null,
        };
    }
}
