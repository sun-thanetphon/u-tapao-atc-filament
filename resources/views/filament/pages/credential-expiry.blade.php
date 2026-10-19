<x-filament-panels::page>

    <div class="uta-expiry-bar">
        <p class="uta-expiry-note">
            อ่านจากชีตผู้ถือใบอนุญาตของหน่วย (แก้ข้อมูลที่ชีต) ระบบส่งอีเมลเตือนเจ้าตัวเมื่อเหลือไม่เกิน {{ \App\Support\CredentialReminderService::WINDOW_DAYS }} วัน
        </p>
        <div class="uta-expiry-actions">
            <x-filament::button color="gray" icon="heroicon-m-arrow-path" wire:click="refreshSheet">
                รีเฟรชจากชีต
            </x-filament::button>
            <x-filament::button color="gray" icon="heroicon-m-envelope" wire:click="sendTest">
                ส่งอีเมลตัวอย่างถึงฉัน
            </x-filament::button>
        </div>
    </div>

    @if ($error)
        <x-filament::section>
            <p><strong>อ่านชีตไม่สำเร็จ:</strong> {{ $error }}</p>
            <p>ตรวจว่าชีตยังเปิดให้ผู้ที่มีลิงก์ดูได้ และตั้งค่า CREDENTIAL_SHEET_ID ถูกต้อง</p>
        </x-filament::section>
    @else
        <x-filament::section heading="รายการวันหมดอายุ (เหลือน้อยสุดก่อน)">
            <table class="uta-expiry-table">
                <thead>
                    <tr>
                        <th>ชื่อ</th>
                        <th>รายการ</th>
                        <th>วันหมดอายุ</th>
                        <th>เหลือ</th>
                        <th>สถานะการเตือน</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        <tr>
                            <td>{{ $item['name'] }}</td>
                            <td>{{ $item['kind'] }}</td>
                            <td>{{ $item['expires_on'] }}</td>
                            <td>{{ $item['days_left'] < 0 ? 'หมดอายุแล้ว' : $item['days_left'] . ' วัน' }}</td>
                            <td>
                                <x-filament::badge :color="$item['state']['color']">{{ $item['state']['label'] }}</x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">ไม่พบข้อมูลในชีต</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        @if ($problems !== [])
            <x-filament::section heading="รายชื่อที่ส่งเมลไม่ได้ / ข้อมูลที่อ่านไม่ได้">
                <table class="uta-expiry-table">
                    <thead>
                        <tr><th>ชื่อในชีต</th><th>เหตุผล</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($problems as $problem)
                            <tr>
                                <td>{{ $problem['name'] }}</td>
                                <td>{{ $problem['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif
    @endif

</x-filament-panels::page>
