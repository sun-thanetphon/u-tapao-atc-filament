<x-mail::message>
@if ($test)
**โหมดทดสอบ: ไม่แสดงชื่อจริง แสดงแค่จำนวน**

@endif
# สรุปการเตือนวันหมดอายุใบอนุญาต

ระบบส่งอีเมลเตือนผู้ถือใบอนุญาต {{ $count }} คนในรอบนี้ (เหลือไม่เกิน 3 เดือนหรือหมดอายุแล้ว)

@if (! $test && $rows !== [])
<x-mail::table>
| ชื่อ | รายการ | วันหมดอายุ | สถานะ |
| :--- | :----- | :--------- | :---- |
@foreach ($rows as $row)
| {{ $row['name'] }} | {{ $row['label'] }} | {{ $row['date'] }} | {{ $row['status'] }} |
@endforeach
</x-mail::table>
@endif

@if ($problemCount > 0)
**ส่งเมลถึงเจ้าตัวไม่ได้ {{ $problemCount }} รายการ กรุณาแจ้งด้วยตนเอง**

@foreach ($problems as $problem)
- {{ $problem['name'] }}: {{ $problem['reason'] }}
@endforeach
@endif

อีเมลนี้ส่งโดยอัตโนมัติ ไม่ต้องตอบกลับ

U-Tapao ATC
</x-mail::message>
