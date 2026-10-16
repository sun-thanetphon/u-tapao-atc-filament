<x-filament-widgets::widget>
    <section class="uta-card uta-study">
        <header class="uta-study-header">
            <x-filament::icon icon="heroicon-o-book-open" class="uta-card-icon" />
            <h2 class="uta-card-title">{{ $title }}</h2>
            <span class="uta-study-desc">{{ $description }}</span>
        </header>

        {{-- หนึ่งวิชาหนึ่งบรรทัด: ชื่อวิชาเป็นลิงก์เปิดเอกสารแรก ถ้ามีหลายเอกสารจะมีลิงก์เล็กต่อท้าย --}}
        <ol class="uta-study-list">
            @foreach ($subjects as $subject)
                @php($first = $subject['links']->first())
                <li class="uta-study-item">
                    <span class="uta-study-no">{{ $loop->iteration }}</span>

                    @if ($first)
                        <a
                            href="{{ $first['url'] }}"
                            target="_blank"
                            rel="noopener"
                            class="uta-study-subject"
                            title="{{ $first['label'] }}"
                        >{{ $subject['name'] }}</a>
                        @if (! empty($first['external']))
                            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="uta-link-ext" />
                        @endif

                        @foreach ($subject['links']->skip(1) as $link)
                            <a
                                href="{{ $link['url'] }}"
                                target="_blank"
                                rel="noopener"
                                class="uta-study-more"
                                title="{{ $link['label'] }}"
                            >ฉบับที่ {{ $loop->iteration + 1 }}</a>
                        @endforeach
                    @else
                        <span class="uta-study-subject is-missing">{{ $subject['name'] }}</span>
                        <span class="uta-study-more is-missing">ยังไม่มีในระบบ</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
</x-filament-widgets::widget>
