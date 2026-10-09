<x-filament-widgets::widget>
    <section class="uta-card uta-board">
        <header class="uta-board-header">
            <h2 class="uta-card-title">เอกสารที่รอการรับทราบ</h2>
            <a href="{{ $allUrl }}" class="uta-board-all">เอกสารทั้งหมด</a>
        </header>

        @if ($strips->isEmpty())
            <div class="uta-board-empty">
                <x-filament::icon icon="heroicon-o-check-badge" class="uta-board-empty-icon" />
                <p>ทุกคนรับทราบเอกสารครบแล้ว</p>
            </div>
        @else
            {{-- แต่ละแถบออกแบบตาม flight progress strip ที่ใช้บนกระดาน ATC --}}
            <ol class="uta-strips">
                @foreach ($strips as $strip)
                    <li>
                        <a href="{{ $strip['url'] }}" class="uta-strip" data-status="{{ $strip['status'] }}">
                            <span class="uta-strip-id">
                                <span class="uta-strip-code">{{ $strip['code'] }}</span>
                                <span class="uta-strip-sections">
                                    @foreach ($strip['sections'] as $prefix)
                                        <span>{{ $prefix }}</span>
                                    @endforeach
                                </span>
                            </span>

                            <span class="uta-strip-name">{{ $strip['name'] }}</span>

                            <span class="uta-strip-progress">
                                <span class="uta-strip-bar"><span style="width: {{ round($strip['rate'] * 100) }}%"></span></span>
                                <span class="uta-strip-count">{{ $strip['done'] }}/{{ $strip['required'] }} คน</span>
                            </span>

                            <span class="uta-strip-rate">{{ number_format($strip['rate'] * 100) }}%</span>
                        </a>
                    </li>
                @endforeach
            </ol>

            @if ($remaining > 0)
                <p class="uta-board-more">และอีก {{ $remaining }} ฉบับที่ยังรับทราบไม่ครบ</p>
            @endif
        @endif
    </section>
</x-filament-widgets::widget>
