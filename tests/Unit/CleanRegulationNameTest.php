<?php

namespace Tests\Unit;

use App\Console\Commands\ImportRegulations;
use PHPUnit\Framework\TestCase;

class CleanRegulationNameTest extends TestCase
{
    public function test_numeric_entities_are_removed_and_text_is_kept(): void
    {
        $this->assertSame(
            'ร่างข้อบังคับ (หมดเวลารับฟังความคิดเห็น) - ร่าง',
            ImportRegulations::cleanName('ร่างข้อบังคับ (&#x274c;&#x274c;หมดเวลารับฟังความคิดเห็น&#x274c;&#x274c;) - ร่าง'),
        );
    }

    public function test_entity_cut_off_at_the_end_is_removed(): void
    {
        $this->assertSame(
            'ร่างข้อกำหนด (หมดระยะเวลารับฟังความคิดเห็น',
            ImportRegulations::cleanName('ร่างข้อกำหนด (&#x274c;&#x274c; หมดระยะเวลารับฟังความคิดเห็น &#x'),
        );
    }

    public function test_named_entities_become_characters_and_plain_ampersands_stay(): void
    {
        $this->assertSame('R&D and Q&A', ImportRegulations::cleanName('R&amp;D and Q&A'));
    }
}
