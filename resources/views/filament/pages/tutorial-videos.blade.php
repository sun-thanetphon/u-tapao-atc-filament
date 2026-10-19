<x-filament-panels::page>

    @forelse ($videos as $video)
        <a class="uta-card uta-video-row" href="{{ url('/admin/learning-video/' . $video->id) }}">
            <x-filament::icon icon="heroicon-s-play-circle" class="uta-video-row-icon" />
            <span class="uta-video-row-text">
                <span class="uta-video-row-title">
                    {{ $video->title }}
                    @unless ($video->publish)
                        <span class="uta-video-draft">(ยังไม่เผยแพร่)</span>
                    @endunless
                </span>
                @if ($video->description)
                    <span class="uta-video-row-desc">{{ $video->description }}</span>
                @endif
            </span>
        </a>
    @empty
        <article class="uta-card">
            <p>ยังไม่มีวิดีโอ</p>
        </article>
    @endforelse

</x-filament-panels::page>
