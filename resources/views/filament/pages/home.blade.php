<x-filament-panels::page>

    <div
        class="uta-home"
        x-data="{ page: 0, viewer: false }"
        x-on:keydown.escape.window="viewer = false"
    >
        <section class="uta-hero" style="--uta-hero-photo: url('{{ asset('images/backgrounds/bg2.jpg') }}')">
            <div class="uta-hero-text">
                <p class="uta-hero-greeting">{{ $greeting }}</p>
                <p class="uta-hero-date">{{ $today }}</p>

                <h2 class="uta-hero-title">{{ $certificate['title'] }}</h2>
                <p class="uta-hero-subtitle">{{ $certificate['subtitle'] }}</p>

                <dl class="uta-cert-facts">
                    <div>
                        <dt>เลขที่</dt>
                        <dd>{{ $certificate['number'] }}</dd>
                    </div>
                    <div>
                        <dt>มีผลถึง</dt>
                        <dd>
                            {{ $certificate['validUntil'] }}
                            <span @class(['uta-cert-remaining', 'is-expired' => ! $certificate['isValid']])>{{ $certificate['remaining'] }}</span>
                        </dd>
                    </div>
                    <div class="uta-cert-facts-wide">
                        <dt>ออกโดย</dt>
                        <dd>{{ $certificate['issuer'] }}</dd>
                    </div>
                </dl>

                <ul class="uta-cert-services">
                    @foreach ($certificate['services'] as $service)
                        <li>{{ $service }}</li>
                    @endforeach
                </ul>

                <button type="button" class="uta-hero-button" x-on:click="viewer = true">
                    <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="uta-hero-button-icon" />
                    เปิดดูใบรับรอง
                </button>
            </div>

            <div class="uta-paper-stack">
                @foreach ($certificate['pages'] as $index => $pageImage)
                    <button
                        type="button"
                        class="uta-paper"
                        data-state="{{ $index === 0 ? 'front' : 'back' }}"
                        x-bind:data-state="page === {{ $index }} ? 'front' : 'back'"
                        x-on:click="page === {{ $index }} ? viewer = true : page = {{ $index }}"
                        aria-label="ใบรับรองหน้า {{ $index + 1 }}"
                    >
                        <img src="{{ $pageImage }}" alt="ใบรับรองบริการการเดินอากาศ หน้า {{ $index + 1 }}">
                    </button>
                @endforeach

                @if (count($certificate['pages']) > 1)
                    <div class="uta-paper-tabs" role="group" aria-label="เลือกหน้าใบรับรอง">
                        @foreach ($certificate['pages'] as $index => $pageImage)
                            <button
                                type="button"
                                class="uta-paper-tab"
                                x-bind:class="{ 'is-active': page === {{ $index }} }"
                                x-on:click="page = {{ $index }}"
                            >หน้า {{ $index + 1 }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <div class="uta-home-grid">
            <div class="uta-home-roster">
                @livewire(\App\Filament\Widgets\DutyRosterPublicUrlTable::class)
            </div>

            <div class="uta-home-news">
                @livewire(\App\Filament\Widgets\NewsPublicUrlTable::class)
            </div>

            <div class="uta-home-docs">
                @livewire(\App\Filament\Widgets\DocumentPublicUrlTable::class)
            </div>
        </div>

        <div
            class="uta-viewer"
            x-show="viewer"
            x-transition.opacity
            x-cloak
            x-on:click.self="viewer = false"
            role="dialog"
            aria-modal="true"
            aria-label="ใบรับรองบริการการเดินอากาศ"
        >
            @foreach ($certificate['pages'] as $index => $pageImage)
                <img
                    src="{{ $pageImage }}"
                    alt="ใบรับรองบริการการเดินอากาศ หน้า {{ $index + 1 }}"
                    class="uta-viewer-image"
                    x-show="page === {{ $index }}"
                >
            @endforeach

            <div class="uta-viewer-bar">
                @foreach ($certificate['pages'] as $index => $pageImage)
                    <button
                        type="button"
                        class="uta-paper-tab"
                        x-bind:class="{ 'is-active': page === {{ $index }} }"
                        x-on:click="page = {{ $index }}"
                    >หน้า {{ $index + 1 }}</button>
                @endforeach
                <button type="button" class="uta-viewer-close" x-on:click="viewer = false">
                    <x-filament::icon icon="heroicon-m-x-mark" class="uta-hero-button-icon" />
                    ปิด
                </button>
            </div>
        </div>
    </div>

</x-filament-panels::page>
