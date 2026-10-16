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

        foreach (static::searchWords($search) as $word) {
            $patterns = array_unique([
                static::likePattern($word),
                static::likePattern(str_replace(range(0, 9), self::THAI_DIGITS, $word)),
            ]);

            $query->where(function (Builder $query) use ($patterns) {
                foreach ($patterns as $pattern) {
                    $query->orWhere('name', 'like', $pattern)
                        ->orWhere('code', 'like', $pattern)
                        ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', $pattern));
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

    private static function likePattern(string $word): string
    {
        return '%' . addcslashes($word, '\\%_') . '%';
    }
}
