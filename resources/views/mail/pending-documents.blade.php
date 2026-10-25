<x-mail::message>
@if ($testRecipientCount !== null)
**โหมดทดสอบ: ถ้าส่งจริงอีเมลนี้จะถึงผู้ใช้ {{ (int) $testRecipientCount }} คน (ยังไม่ได้ส่งถึงใคร)**

@endif
# เตือน: มีเอกสารที่ยังไม่ได้รับทราบ {{ $total }} ฉบับ

ระบบพบว่าคุณยังไม่ได้อ่านและรับทราบเอกสารต่อไปนี้ กรุณาเข้าระบบเพื่ออ่านและรับทราบโดยเร็ว

@foreach ($listed as $item)
- **{{ $item['name'] }}** ({{ $item['code'] }})
@endforeach
@if ($more > 0)
- และอีก {{ $more }} ฉบับ
@endif

<x-mail::button :url="$url">
เปิดรายการเอกสารและรับทราบ
</x-mail::button>

ถ้ารับทราบครบแล้ว ไม่ต้องดำเนินการใด อีเมลนี้ส่งโดยอัตโนมัติ ไม่ต้องตอบกลับ

U-Tapao ATC
</x-mail::message>
