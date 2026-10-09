<x-filament-widgets::widget>
    <section class="uta-card uta-latest">
        <header class="uta-card-header">
            <h2 class="uta-card-title">รับทราบล่าสุด</h2>
        </header>

        @if ($items->isEmpty())
            <p class="uta-empty">ยังไม่มีการรับทราบเอกสาร</p>
        @else
            <ul class="uta-latest-list">
                @foreach ($items as $item)
                    @php($date = \Illuminate\Support\Carbon::parse($item->acknowledge_date)->locale('th'))
                    <li class="uta-latest-row">
                        <span class="uta-latest-avatar" aria-hidden="true">{{ mb_substr($item->user->firstname, 0, 1) }}</span>
                        <span class="uta-latest-body">
                            <span class="uta-latest-name">{{ $item->user->getFullName() }}</span>
                            <span class="uta-latest-doc">{{ $item->document->name }}</span>
                        </span>
                        <time class="uta-latest-time" datetime="{{ $date->toIso8601String() }}" title="{{ $date->translatedFormat('j M') }} {{ $date->year + 543 }} {{ $date->format('H:i') }}">
                            {{ $date->diffForHumans(short: true) }}
                        </time>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-filament-widgets::widget>
