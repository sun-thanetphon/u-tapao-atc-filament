<?php

namespace App\Enums;

enum PublicUrlCategoryEnum: string
{
    case DOCUMENT = 'document';
    case DUTY_ROSTER = 'duty-roster';
    case NEWS = 'news';

    public function label(): string
    {
        return match ($this) {
            self::DOCUMENT => 'เอกสารสำคัญ',
            self::DUTY_ROSTER => 'ตารางเวร',
            self::NEWS => 'ข่าวประชาสัมพันธ์',
        };
    }
}
