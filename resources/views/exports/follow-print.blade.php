<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <title>ผู้รับทราบ {{ $document->code }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;600&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 18mm 15mm; }
        * { box-sizing: border-box; }
        html { color-scheme: light; background: #fff; }
        body { margin: 0; background: #fff; font-family: 'IBM Plex Sans Thai', 'Sarabun', sans-serif; font-size: 13px; color: #111; }
        .bar { padding: 10px 16px; background: #0b1b34; color: #fff; display: flex; gap: 12px; align-items: center; }
        .bar button { padding: 6px 14px; border: 0; border-radius: 6px; background: #fff; cursor: pointer; font: inherit; }
        .page { max-width: 190mm; margin: 0 auto; padding: 16px; }
        h1 { margin: 0 0 4px; font-size: 20px; }
        .meta { color: #555; margin: 0 0 14px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; }
        thead th { background: #e8edf5; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        @media print { .bar { display: none; } .page { padding: 0; } }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.print()">พิมพ์ / บันทึกเป็น PDF</button>
        <span>ในหน้าต่างพิมพ์ เลือกปลายทางเป็น "Save as PDF"</span>
    </div>
    <div class="page">
        <h1>{{ $document->name }}</h1>
        <p class="meta">รหัส {{ $document->code }} · ผู้รับทราบ {{ $users->count() }} คน · ออกรายงาน {{ now()->format('d-m-Y') }}</p>

        <table>
            <thead>
                <tr>
                    <th style="width: 8%">#</th>
                    <th>ชื่อผู้รับทราบ</th>
                    <th style="width: 16%">แผนก</th>
                    <th style="width: 20%">วันที่</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $index => $user)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $user->getFullName() }}</td>
                        <td>{{ $user->section->prefix }}</td>
                        <td>{{ \Carbon\Carbon::parse($user->acknowledges->firstWhere('document_id', $document->id)->acknowledge_date)->format('d-m-Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">ยังไม่มีผู้รับทราบ</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
