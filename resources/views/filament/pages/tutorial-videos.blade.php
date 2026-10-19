<x-filament-panels::page>

    @forelse ($videos as $video)
        <article class="uta-card uta-video">
            <h2 class="uta-service-title">
                {{ $video->title }}
                @unless ($video->publish)
                    <span class="uta-video-draft">(ยังไม่เผยแพร่)</span>
                @endunless
            </h2>

            @if ($video->isUpload())
                <video class="uta-video-player" controls preload="metadata" src="{{ url('/tutorial-video/' . $video->id) }}"></video>
            @elseif ($video->embedSrc())
                <iframe
                    class="uta-video-player"
                    src="{{ $video->embedSrc() }}"
                    title="{{ $video->title }}"
                    loading="lazy"
                    allow="fullscreen; picture-in-picture"
                    referrerpolicy="strict-origin-when-cross-origin"
                    allowfullscreen
                ></iframe>
            @endif

            @if ($video->description)
                <p>{{ $video->description }}</p>
            @endif
        </article>
    @empty
        <article class="uta-card">
            <p>ยังไม่มีวิดีโอแนะนำ</p>
        </article>
    @endforelse

</x-filament-panels::page>
