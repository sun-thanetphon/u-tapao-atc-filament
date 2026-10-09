@php
    $segments = 24;
    $filled = $rate === null ? 0 : (int) round($rate * $segments);
@endphp

<x-filament-widgets::widget>
    <section class="uta-card uta-overview">
        <div class="uta-overview-main">
            <h2 class="uta-overview-label">การรับทราบเอกสาร</h2>

            <p class="uta-overview-rate">
                @if ($rate === null)
                    <span class="uta-overview-rate-value">–</span>
                @else
                    <span class="uta-overview-rate-value">{{ number_format($rate * 100) }}</span><span class="uta-overview-rate-unit">%</span>
                @endif
            </p>

            {{-- มาตรวัดแบบไฟนำร่อนลงจอด: หนึ่งช่องเท่ากับ 1/24 ของการรับทราบที่ต้องมี --}}
            <div class="uta-meter" role="img" aria-label="รับทราบแล้ว {{ number_format($done) }} จาก {{ number_format($required) }} ครั้ง">
                @for ($i = 0; $i < $segments; $i++)
                    <span @class(['uta-meter-cell', 'is-on' => $i < $filled])></span>
                @endfor
            </div>

            <p class="uta-overview-caption">
                รับทราบแล้ว <strong>{{ number_format($done) }}</strong> จาก {{ number_format($required) }} ครั้งที่ต้องรับทราบ
            </p>
        </div>

        <dl class="uta-overview-figures">
            <div>
                <dt>เอกสารเผยแพร่</dt>
                <dd>{{ number_format($published) }}</dd>
            </div>
            <div>
                <dt>รอรับทราบ</dt>
                <dd @class(['is-alert' => $pending > 0])>{{ number_format($pending) }}</dd>
            </div>
            <div>
                <dt>ผู้ใช้งาน</dt>
                <dd>{{ number_format($users) }}</dd>
            </div>
        </dl>
    </section>
</x-filament-widgets::widget>
