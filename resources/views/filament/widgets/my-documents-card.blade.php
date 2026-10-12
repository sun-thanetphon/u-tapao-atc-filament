<x-filament-widgets::widget>
    <section class="uta-card uta-mine">
        <header class="uta-board-header">
            <h2 class="uta-card-title">เอกสารของฉัน</h2>
            <a href="{{ $allUrl }}" class="uta-board-all">ดูทั้งหมด</a>
        </header>

        <div class="uta-mine-body">
            <div class="uta-mine-summary">
                <p @class(['uta-mine-count', 'is-alert' => $pendingCount > 0])>{{ number_format($pendingCount) }}</p>
                <p class="uta-mine-label">
                    @if ($pendingCount > 0)
                        ฉบับที่รอให้คุณรับทราบ
                    @else
                        คุณรับทราบเอกสารครบแล้ว
                    @endif
                </p>
                <p class="uta-mine-new">เอกสารใหม่ใน {{ $newDays }} วันที่ผ่านมา {{ number_format($newCount) }} ฉบับ</p>
            </div>

            @if ($pending->isNotEmpty())
                <ul class="uta-mine-list">
                    @foreach ($pending as $document)
                        <li>
                            <a href="{{ $pendingUrl }}" class="uta-mine-item">
                                <span class="uta-mine-code">{{ $document->code }}</span>
                                <span class="uta-mine-name">{{ $document->name }}</span>
                                <x-filament::icon icon="heroicon-m-chevron-right" class="uta-mine-arrow" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <x-filament::icon icon="heroicon-o-check-badge" class="uta-board-empty-icon" />
            @endif
        </div>

        @if ($pendingCount > $pending->count())
            <p class="uta-board-more">
                และอีก {{ $pendingCount - $pending->count() }} ฉบับ
                <a href="{{ $pendingUrl }}" class="uta-board-all">ดูรายการที่รอรับทราบทั้งหมด</a>
            </p>
        @endif
    </section>
</x-filament-widgets::widget>
