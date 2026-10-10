<x-mail::message>
# ผลการสมัครใช้งาน

เรียน {{ $user->getFullName() }}

ขออภัย คำขอสมัครใช้งาน U-Tapao ATC ของคุณไม่ได้รับการอนุมัติ
@if (filled($reason))

เหตุผล: {{ $reason }}
@endif

U-Tapao ATC
</x-mail::message>
