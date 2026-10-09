<x-filament-widgets::widget>
    <section class="uta-card">
        <header class="uta-card-header">
            <x-filament::icon :icon="$icon" class="uta-card-icon" />
            <h2 class="uta-card-title">{{ $heading }}</h2>
        </header>

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
