<x-filament-panels::page>

    <article class="uta-card uta-video">
        @if ($video->isUpload())
            <video class="uta-video-player" controls autoplay preload="metadata" src="{{ url('/tutorial-video/' . $video->id) }}"></video>
        @elseif ($video->embedSrc())
            <iframe
                class="uta-video-player"
                src="{{ $video->embedSrc() }}"
                title="{{ $video->title }}"
                allow="fullscreen; picture-in-picture"
                referrerpolicy="strict-origin-when-cross-origin"
                allowfullscreen
            ></iframe>
        @endif

        @if ($video->description)
            <p>{{ $video->description }}</p>
        @endif
    </article>

    <x-filament::button tag="a" href="{{ url('/admin/learning-video') }}" color="gray" icon="heroicon-m-arrow-left">
        กลับไปรายการ
    </x-filament::button>

</x-filament-panels::page>
