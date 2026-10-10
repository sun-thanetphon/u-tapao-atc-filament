@php
    /** @var \App\Support\EmailImportResult|null $result */
    $limit = 50;
    $shown = $result ? array_slice($result->skipped, 0, $limit) : [];
    $more = $result ? max(0, count($result->skipped) - $limit) : 0;
@endphp

<div style="display:flex;flex-direction:column;gap:0.75rem;">
    @if ($error)
        <p style="color:#dc2626;font-weight:600;">{{ $error }}</p>
    @elseif ($result)
        <ul style="list-style:disc;padding-left:1.25rem;">
            <li><strong>จะอัปเดตอีเมล:</strong> {{ number_format(count($result->toUpdate)) }} ผู้ใช้</li>
            <li><strong>ไม่เปลี่ยนแปลง:</strong> {{ number_format(count($result->unchanged)) }} ผู้ใช้</li>
            <li><strong>ข้าม:</strong> {{ number_format(count($result->skipped)) }} แถว</li>
        </ul>

        @if (count($result->toUpdate) === 0)
            <p style="color:#d97706;">ไม่มีรายการที่จะอัปเดต</p>
        @endif

        @if (count($shown) > 0)
            <div style="max-height:18rem;overflow:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.875rem;">
                    <thead>
                        <tr style="text-align:left;border-bottom:1px solid #9ca3af;">
                            <th style="padding:0.25rem 0.5rem;">แถว</th>
                            <th style="padding:0.25rem 0.5rem;">username</th>
                            <th style="padding:0.25rem 0.5rem;">อีเมล</th>
                            <th style="padding:0.25rem 0.5rem;">เหตุผลที่ข้าม</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($shown as $skipped)
                            <tr style="border-bottom:1px solid #e5e7eb;">
                                <td style="padding:0.25rem 0.5rem;">{{ $skipped['row'] }}</td>
                                <td style="padding:0.25rem 0.5rem;">{{ $skipped['username'] }}</td>
                                <td style="padding:0.25rem 0.5rem;">{{ $skipped['email'] }}</td>
                                <td style="padding:0.25rem 0.5rem;">{{ $skipped['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($more > 0)
                <p>และอีก {{ number_format($more) }} แถว</p>
            @endif
        @endif
    @endif
</div>
