{{-- หน้า login แสดงเฉพาะตราใหญ่ หน้าอื่นแสดงตราคู่ชื่อหน่วย --}}
<img src="{{ asset('assets/images/u-tapao-circle.png') }}" alt="U-Tapao ATC" class="uta-brand-mark">
@unless (request()->routeIs('filament.admin.auth.*'))
    <span class="uta-brand-name">
        <span class="uta-brand-title">U-Tapao</span>
        <span class="uta-brand-sub">Air Traffic Control</span>
    </span>
@endunless
