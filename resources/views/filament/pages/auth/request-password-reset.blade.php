<x-filament-panels::page.simple>
    @if (filament()->hasLogin())
        <x-slot name="subheading">
            {{ $this->loginAction }}
        </x-slot>
    @endif

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE, scopes: $this->getRenderHookScopes()) }}

    <x-filament-panels::form id="form" wire:submit="request">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>

    {{-- บัญชีที่ไม่มีอีเมล: ส่งคำขอให้ผู้ดูแลระบบช่วยตั้งรหัสผ่าน --}}
    <div class="uta-admin-request">
        <h2 class="uta-admin-request-title">ไม่มีอีเมลในระบบ?</h2>
        <p class="uta-admin-request-desc">กรอกชื่อผู้ใช้เพื่อขอให้ผู้ดูแลระบบช่วยตั้งรหัสผ่าน</p>

        <x-filament-panels::form id="usernameForm" wire:submit="requestByUsername" class="uta-admin-request-form">
            {{ $this->usernameForm }}

            <x-filament::button type="submit" color="gray" class="w-full" wire:target="requestByUsername">
                ส่งคำขอถึงผู้ดูแลระบบ
            </x-filament::button>
        </x-filament-panels::form>
    </div>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER, scopes: $this->getRenderHookScopes()) }}
</x-filament-panels::page.simple>
