<?php

namespace App\Support;

/**
 * ป้ายและสีของประเภทเอกสาร แปลงจากชื่อประเภท เช่น "WI (Work Instruction)" => ป้าย "WI"
 * สีคงที่ตามชื่อ จึงไม่ต้องตั้งค่าเมื่อเพิ่มประเภทใหม่
 */
class CategoryStyle
{
    private const TONES = 8;

    /** ป้ายสั้น: ข้อความก่อนวงเล็บ ถ้าไม่มีวงเล็บใช้ชื่อเต็ม */
    public static function badge(?string $name): string
    {
        if (blank($name)) {
            return 'Other';
        }

        return trim(preg_replace('/\s*\([^()]*\)\s*$/u', '', $name)) ?: $name;
    }

    /** ชื่อเต็ม: ข้อความในวงเล็บ ถ้าไม่มีวงเล็บไม่ต้องแสดงซ้ำ */
    public static function fullName(?string $name): ?string
    {
        return filled($name) && preg_match('/\(([^)]+)\)\s*$/u', $name, $m) ? $m[1] : null;
    }

    /**
     * หมายเลขโทนสี 0..7 (ดู .uta-cat-tone-N ในธีม) แบ่งตาม id ของประเภท
     * ประเภทที่ id ต่อเนื่องกันจึงได้สีต่างกันแน่นอน ซ้ำเฉพาะเมื่อมีเกิน 8 ประเภท
     */
    public static function tone(?int $categoryId): int
    {
        return $categoryId === null ? 9 : $categoryId % self::TONES;
    }
}
