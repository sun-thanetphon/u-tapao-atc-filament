<x-mail::message>
# มีผู้สมัครใช้งานใหม่

มีผู้สมัครใช้งานระบบ U-Tapao ATC รอการอนุมัติ

- ชื่อ: {{ $applicant->getFullName() }}
- แผนก: {{ $applicant->section?->prefix }}
- ชื่อผู้ใช้: {{ $applicant->username }}

<x-mail::button :url="$url">
ไปที่หน้าผู้ใช้งาน
</x-mail::button>

U-Tapao ATC
</x-mail::message>
