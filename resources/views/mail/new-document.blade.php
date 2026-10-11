<x-mail::message>
@if ($testRecipientCount !== null)
**โหมดทดสอบ: ถ้าส่งจริงอีเมลนี้จะถึงผู้ใช้ {{ (int) $testRecipientCount }} คน (ยังไม่ได้ส่งถึงใคร)**

@endif
# เอกสารใหม่ที่ต้องรับทราบ

มีเอกสารใหม่ที่แผนกของคุณต้องอ่านและรับทราบ

- **ชื่อเอกสาร:** {{ $document->name }}
- **รหัสเอกสาร:** {{ $document->code }}
@if ($category)
- **ประเภท:** {{ $category }}
@endif
@if ($uploader)
- **อัปโหลดโดย:** {{ $uploader }}
@endif
- **แผนกที่ต้องรับทราบ:** {{ $sections }}

<x-mail::button :url="$url">
เปิดเอกสารและรับทราบ
</x-mail::button>

อีเมลนี้ส่งโดยอัตโนมัติ ไม่ต้องตอบกลับ

U-Tapao ATC
</x-mail::message>
