<?php

/**
 * ชุดอ่านสอบที่แสดงบนหน้า Home ทุกแผนกเห็นเหมือนกัน
 *
 * subjects: วิชาเรียงตามประกาศสอบ แต่ละวิชาชี้ไปที่เอกสารในระบบ (documents = id ของเอกสาร) และ/หรือเว็บภายนอก (url)
 * เอกสารที่ผู้ใช้เปิดไม่ได้หรือถูกลบจะไม่แสดง ถ้าวิชาไหนไม่เหลือให้เปิดเลยจะขึ้นว่ายังไม่มีในระบบ
 */
return [
    'exam' => [
        'title' => 'ชุดอ่านสอบเลื่อนฐานะ ATC',
        'description' => 'วิชาสอบภาคทฤษฎี 8 วิชา ตามประกาศสอบเลื่อนฐานะนายทหารประทวน สายควบคุมการจราจรทางอากาศ',
        'subjects' => [
            ['name' => 'Rules of the Air (Annex 2)', 'documents' => [97]],
            ['name' => 'Aeronautical Telecommunications (Annex 10 Vol. 2)', 'documents' => [108, 109]],
            ['name' => 'Air Traffic Services (Annex 11)', 'documents' => [114]],
            ['name' => 'Aerodromes (Annex 14)', 'documents' => [117]],
            ['name' => 'Aeronautical Information Services (Annex 15)', 'documents' => [119]],
            ['name' => 'Air Traffic Management (Doc 4444)', 'documents' => [128]],
            ['name' => 'AIP Thailand', 'url' => 'https://aip.caat.or.th/', 'url_label' => 'เปิดเว็บ AIP Thailand (ฉบับที่มีผลอยู่ปัจจุบัน)'],
            ['name' => 'ATSOM VTBU (Air Traffic Service Operation Manual)', 'documents' => [3]],
        ],
    ],
];
