<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * ค้นหาเอกสารให้ใช้ง่ายขึ้นกว่าค่าเริ่มต้นของ Filament: แต่ละคำต้องตรงกับชื่อ รหัส หรือชื่อประเภทอย่างใดอย่างหนึ่ง
 * - คำที่คั่นด้วย - _ / . ถือเป็นคำแยก ("Part-ATCO" ค้นเจอทั้ง "Part ATCO" และ "Part-ATCO")
 * - เลขอารบิกกับเลขไทยค้นเจอกัน ("74" เจอ "๗๔") เพราะเอกสารเก่าบางฉบับยังใช้เลขไทย
 */
trait SearchesDocuments
{
    private const THAI_DIGITS = ['๐', '๑', '๒', '๓', '๔', '๕', '๖', '๗', '๘', '๙'];

    protected function applyGlobalSearchToTableQuery(Builder $query): Builder
    {
        $search = $this->getTableSearch();

        if (blank($search)) {
            return $query;
        }

        $words = static::searchWords($search);

        if ($words === []) {
            return $query;
        }

        // เรียงตามความตรง: คำที่เป็นคำเดี่ยวในชื่อ (เช่น "22" ใน "ฉบับที่ 22") มาก่อนคำที่เป็นแค่ส่วนของคำอื่น (เช่น "2022")
        // ตัวเรียงนี้มาก่อนการเรียงปกติของตาราง (ใหม่สุดก่อน) จึงใช้เป็นตัวตัดสินเมื่อคะแนนเท่ากัน
        $query->orderByRaw(
            '(' . implode(' + ', array_fill(0, count($words), '(name REGEXP ?)')) . ') DESC',
            array_map(
                fn (string $word): string => static::wholeWordRegex($word, str_replace(range(0, 9), self::THAI_DIGITS, $word)),
                $words,
            ),
        );

        foreach ($words as $word) {
            $thai = str_replace(range(0, 9), self::THAI_DIGITS, $word);

            // ตัวเลขล้วน ("22") ต้องเป็นเลขทั้งก้อนในชื่อ ไม่ใช่ส่วนหนึ่งของเลขอื่น (เช่น 2022, 220)
            // และไม่ค้นในรหัสเอกสารที่ผู้ใช้มองไม่เห็นในรายการ ยกเว้นพิมพ์เลขยาวพอจะเป็นรหัส
            if (ctype_digit($word)) {
                $regex = static::wholeWordRegex($word, $thai);

                $query->where(function (Builder $query) use ($regex, $word) {
                    $query->where('name', 'regexp', $regex)
                        ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'regexp', $regex));

                    if (strlen($word) >= 7) {
                        $query->orWhere('code', 'like', static::likePattern($word));
                    }
                });

                continue;
            }

            $patterns = array_unique([static::likePattern($word), static::likePattern($thai)]);

            $query->where(function (Builder $query) use ($patterns, $word) {
                foreach ($patterns as $pattern) {
                    $query->orWhere('name', 'like', $pattern)
                        ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', $pattern));

                    // รหัสเอกสาร (เช่น D2610...) ค้นได้เมื่อพิมพ์ยาวพอ คำสั้น ๆ ไม่ไปตรงกับรหัสโดยบังเอิญ
                    if (mb_strlen($word) >= 4) {
                        $query->orWhere('code', 'like', $pattern);
                    }
                }
            });
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    public static function searchWords(string $search): array
    {
        $search = str_replace(self::THAI_DIGITS, range(0, 9), $search);

        return array_values(array_filter(
            preg_split('/[\s\-_\/.,]+/u', $search),
            fn (string $word): bool => $word !== '',
        ));
    }

    private static function wholeWordRegex(string $word, string $thai): string
    {
        return '(^|[^[:alnum:]])(' . static::regexEscape($word) . '|' . static::regexEscape($thai) . ')([^[:alnum:]]|$)';
    }

    private static function regexEscape(string $word): string
    {
        return preg_replace('/[\\\\^$.*+?()\[\]{}|]/', '\\\\$0', $word);
    }

    private static function likePattern(string $word): string
    {
        return '%' . addcslashes($word, '\\%_') . '%';
    }
}
