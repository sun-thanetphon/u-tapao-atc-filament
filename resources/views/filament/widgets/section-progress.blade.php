<x-filament-widgets::widget>
    <section class="uta-card uta-sections">
        <header class="uta-card-header">
            <h2 class="uta-card-title">ความคืบหน้าตามแผนก</h2>
        </header>

        <ul class="uta-section-list">
            @foreach ($sections as $section)
                <li class="uta-section-row">
                    <span class="uta-section-prefix">{{ $section['prefix'] }}</span>
                    <span class="uta-section-body">
                        <span class="uta-section-line">
                            <span class="uta-section-name">{{ $section['name'] }}</span>
                            <span class="uta-section-rate">
                                {{ $section['rate'] === null ? 'ไม่มีเอกสาร' : number_format($section['rate'] * 100) . '%' }}
                            </span>
                        </span>
                        <span class="uta-strip-bar">
                            <span style="width: {{ round(($section['rate'] ?? 0) * 100) }}%"></span>
                        </span>
                        <span class="uta-section-count">{{ number_format($section['done']) }} จาก {{ number_format($section['required']) }} ครั้ง</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
</x-filament-widgets::widget>
