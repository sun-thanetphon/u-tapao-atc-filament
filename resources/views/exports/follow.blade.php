<table>
    <thead>
        <tr>
            <th>ชื่อเอกสาร</th>
            <th>ชื่อผู้รับทราบ</th>
            <th>แผนก</th>
            <th>วันที่</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($users as $user)
            <tr>
                <td>{{ $document->name }}</td>
                <td>{{ $user->getFullName() }}</td>
                <td>{{ $user->section->prefix }}</td>
                <td>{{ \Carbon\Carbon::parse($user->acknowledges->firstWhere('document_id', $document->id)->acknowledge_date)->format('d-m-Y') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
