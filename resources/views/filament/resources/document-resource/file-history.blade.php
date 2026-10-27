@php
    /**
     * @var \App\Models\Document $document
     * @var \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, \App\Models\DocumentFile>> $revisions ไฟล์จัดกลุ่มตามรอบ รอบใหม่ก่อน
     * @var \Illuminate\Support\Collection<int, int> $acknowledged จำนวนผู้รับทราบต่อรอบ
     */
@endphp

<div style="display:flex;flex-direction:column;gap:1rem;">
    @forelse ($revisions as $revision => $files)
        <section style="border:1px solid rgba(156,163,175,.4);border-radius:.5rem;padding:.75rem 1rem;">
            <p style="font-weight:600;">
                ฉบับที่ {{ $revision }}
                @if ($revision === $document->revision)
                    <span style="font-weight:400;opacity:.7;">(ฉบับปัจจุบัน)</span>
                @endif
                @if ($document->isNeedToAck())
                    <span style="font-weight:400;"> · รับทราบ {{ number_format($acknowledged[$revision] ?? 0) }} คน</span>
                @endif
            </p>

            <ul style="margin-top:.5rem;display:flex;flex-direction:column;gap:.5rem;">
                @foreach ($files->sortByDesc('id') as $file)
                    <li style="display:flex;flex-wrap:wrap;align-items:baseline;gap:.25rem .75rem;font-size:.875rem;">
                        <span>{{ $file->created_at->format('d-m-Y H:i') }}</span>
                        <span style="opacity:.7;">โดย {{ $file->uploader?->getFilamentName() ?? 'ไม่ทราบ' }}</span>
                        @if ($file->minor)
                            <span style="border:1px solid rgba(156,163,175,.6);border-radius:9999px;padding:0 .5rem;font-size:.75rem;">แก้ไขเล็กน้อย</span>
                        @endif
                        <x-filament::link :href="route('document.file', [$document, $file])" target="_blank" rel="noopener" icon="heroicon-m-arrow-top-right-on-square" icon-position="after" size="sm">
                            เปิดไฟล์
                        </x-filament::link>
                        @if ($file->note)
                            <span style="flex-basis:100%;">{{ $file->note }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p style="opacity:.7;">ยังไม่มีประวัติฉบับ</p>
    @endforelse
</div>
