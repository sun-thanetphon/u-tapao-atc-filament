<x-filament-panels::page.simple>
    {{ $this->content }}

    {{-- บัญชีที่ไม่มีอีเมล: ส่งคำขอให้ผู้ดูแลระบบช่วยตั้งรหัสผ่าน --}}
    <div class="uta-admin-request">
        <h2 class="uta-admin-request-title">ไม่มีอีเมลในระบบ?</h2>
        <p class="uta-admin-request-desc">กรอกชื่อผู้ใช้เพื่อขอให้ผู้ดูแลระบบช่วยตั้งรหัสผ่าน</p>

        <form id="usernameForm" wire:submit="requestByUsername" class="uta-admin-request-form">
            {{ $this->usernameForm }}

            <x-filament::button type="submit" color="gray" class="uta-admin-request-submit" wire:target="requestByUsername">
                ส่งคำขอถึงผู้ดูแลระบบ
            </x-filament::button>
        </form>
    </div>
</x-filament-panels::page.simple>
