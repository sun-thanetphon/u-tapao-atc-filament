<x-filament-widgets::widget>
    <section class="uta-card">
        <header class="uta-card-header">
            <x-filament::icon :icon="$icon" class="uta-card-icon" />
            <h2 class="uta-card-title">{{ $heading }}</h2>
        </header>

        @if (count($images))
            <div
                class="uta-slider"
                x-data="{ active: 0, total: {{ count($images) }} }"
                x-init="if (! window.matchMedia('(prefers-reduced-motion: reduce)').matches) setInterval(() => active = (active + 1) % total, 5000)"
            >
                @foreach ($images as $index => $image)
                    <img
                        src="{{ $image }}"
                        alt=""
                        class="uta-slider-image"
                        x-bind:class="{ 'is-active': active === {{ $index }} }"
                    >
                @endforeach

                @if (count($images) > 1)
                    <div class="uta-slider-dots">
                        @foreach ($images as $index => $image)
                            <button
                                type="button"
                                class="uta-slider-dot"
                                x-bind:class="{ 'is-active': active === {{ $index }} }"
                                x-on:click="active = {{ $index }}"
                                aria-label="รูปที่ {{ $index + 1 }}"
                            ></button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        @if ($links->isEmpty())
            <p class="uta-empty">ยังไม่มีลิงก์ในหมวดนี้</p>
        @else
            <ul class="uta-links">
                @foreach ($links as $link)
                    <li>
                        <a href="{{ $link->url }}" target="_blank" rel="noopener" class="uta-link">
                            <span class="uta-link-name">{{ $link->name }}</span>
                            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="uta-link-ext" />
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-filament-widgets::widget>
