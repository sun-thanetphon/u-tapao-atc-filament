<x-mail::message>
# บัญชีของคุณได้รับการอนุมัติแล้ว

เรียน {{ $user->getFullName() }}

บัญชี U-Tapao ATC ของคุณได้รับการอนุมัติแล้ว สิทธิ์การใช้งาน: {{ $role }}

<x-mail::button :url="$url">
เข้าสู่ระบบ
</x-mail::button>

U-Tapao ATC
</x-mail::message>
