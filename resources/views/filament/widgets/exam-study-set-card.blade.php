<x-filament-widgets::widget>
    <section class="uta-card uta-study">
        <header class="uta-card-header">
            <x-filament::icon icon="heroicon-o-book-open" class="uta-card-icon" />
            <div>
                <h2 class="uta-card-title">{{ $title }}</h2>
                <p class="uta-study-desc">{{ $description }}</p>
            </div>
        </header>

        <ol class="uta-study-list">
            @foreach ($subjects as $subject)
                <li class="uta-study-item">
                    <span class="uta-study-no">{{ $loop->iteration }}</span>
                    <div class="uta-study-body">
                        <p class="uta-study-subject">{{ $subject['name'] }}</p>
                        @forelse ($subject['links'] as $link)
                            <a
                                href="{{ $link['url'] }}"
                                target="_blank"
                                rel="noopener"
                                class="uta-study-link"
                            >
                                <span class="uta-study-link-name">{{ $link['label'] }}</span>
                                <x-filament::icon
                                    :icon="! empty($link['external']) ? 'heroicon-m-arrow-top-right-on-square' : 'heroicon-m-document-text'"
                                    class="uta-link-ext"
                                />
                            </a>
                        @empty
                            <p class="uta-study-missing">ยังไม่มีในระบบ</p>
                        @endforelse
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
</x-filament-widgets::widget>
