<table>
    <thead>
        <tr>
            <th colspan="5" style="justify-self: center">({{ $document->code }}) {{ $document->name }}</th>
        </tr>
        <tr>
            <th>#</th>
            <th>แผนก</th>
            <th>ชื่อ-สกุล</th>
            <th>สถานะ</th>
            <th>เวลา</th>
        </tr>
    </thead>
    <tbody>

        @foreach ($users as $index => $user)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $user->section->prefix }}</td>
                <td>{{ $user->getFullName() }}</td>
                <td>
                    {{ $user->isAcknowledged($document->id) ? 'รับทราบแล้ว' : 'ยังไม่รับทราบ' }}
                </td>
                <td>{{ $user->acknowledges()->first()?->acknowledge_date ?? '-' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
