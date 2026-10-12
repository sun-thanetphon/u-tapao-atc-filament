<div class="uta-cats" role="group" aria-label="เลือกประเภทเอกสาร">
    @foreach ($cards as $card)
        <button
            type="button"
            wire:click="$set('category', {{ \Illuminate\Support\Js::from($card['key']) }})"
            @class(['uta-cat-card', 'is-active' => $active === $card['key']])
            aria-pressed="{{ $active === $card['key'] ? 'true' : 'false' }}"
        >
            <span class="uta-cat-badge uta-cat-tone-{{ $card['tone'] }}">{{ $card['badge'] }}</span>
            @if (filled($card['full']))
                <span class="uta-cat-name">{{ $card['full'] }}</span>
            @endif
            <span class="uta-cat-meta">
                {{ number_format($card['total']) }} ฉบับ
                @if ($card['pending'] > 0)
                    <span class="uta-cat-pending">รอรับทราบ {{ number_format($card['pending']) }}</span>
                @endif
            </span>
        </button>
    @endforeach
</div>
