<x-filament-panels::layout.base :livewire="$livewire">
    <div class="uta-login">
        <aside class="uta-login-brand">
            <div class="uta-login-brand-inner">
                <img src="{{ asset('assets/images/u-tapao-circle.png') }}" alt="U-Tapao ATC" class="uta-login-logo">
                <div>
                    <h1 class="uta-login-name">U-Tapao ATC</h1>
                    <p class="uta-login-desc">ระบบจัดเก็บและติดตามการรับทราบเอกสาร</p>
                </div>
            </div>
            <div class="uta-login-checker" aria-hidden="true"></div>
        </aside>

        <main class="uta-login-panel">
            <div class="uta-login-panel-inner">
                {{ $slot }}
            </div>
        </main>
    </div>

    {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::FOOTER, scopes: $livewire->getRenderHookScopes()) }}
</x-filament-panels::layout.base>
