<?php

namespace App\Support;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Concerns\WithReadFilter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Maatwebsite\Excel\Excel;

/**
 * นำเข้าอีเมลผู้ใช้จำนวนมากจากไฟล์ CSV/Excel โดยจับคู่ด้วย username เท่านั้น
 * แยกเป็น 3 ขั้น: readFile (อ่านไฟล์) -> analyse (ตรวจ ไม่เขียนฐานข้อมูล) -> apply (ตรวจซ้ำใน transaction แล้วเขียน)
 */
class EmailImport
{
    public const MAX_ROWS = 1000;

    public const MAX_BYTES = 2 * 1024 * 1024;

    /** จำนวนแถวดิบสูงสุดที่ยอมอ่าน (เผื่อแถวว่างคั่น) ถ้าถึงค่านี้ถือว่าไฟล์ใหญ่เกิน */
    public const READ_ROW_LIMIT = 2 * self::MAX_ROWS + 2;

    public const READ_COLUMN_LIMIT = 30;

    /** CSV: จำนวนแถวดิบสูงสุดที่ยอมสแกน และจำนวนแถวว่างติดกันสูงสุด */
    public const CSV_MAX_LINES = 50000;

    public const CSV_MAX_BLANK_RUN = 5000;

    /**
     * อ่านไฟล์เป็นแถวข้อมูล (row = เลขแถวในไฟล์ โดยหัวตารางคือแถว 1) ข้ามแถวว่างเปล่า
     *
     * @return list<array{row:int,username:string,email:string}>
     */
    public function readFile(string $path, string $extension): array
    {
        $extension = strtolower($extension);
        $type = match ($extension) {
            'csv' => Excel::CSV,
            'xlsx' => Excel::XLSX,
            'xls' => Excel::XLS,
            default => throw new EmailImportException('รองรับเฉพาะไฟล์ .csv, .xlsx และ .xls'),
        };

        // ตรวจขนาดก่อนอ่านเสมอ (ขีดจำกัดของ Livewire ตอนอัปโหลดสูงกว่านี้มาก)
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new EmailImportException('ไฟล์ใหญ่เกิน 2 MB');
        }

        // CSV อ่านแบบ stream ทีละแถว เก็บเฉพาะ 2 คอลัมน์ที่ต้องใช้ จึงไม่กินหน่วยความจำตามขนาด/รูปทรงของไฟล์
        if ($extension === 'csv') {
            return $this->readCsv($path);
        }

        $import = new class($extension === 'csv' ? $this->sniffDelimiter($path) : ',', $extension !== 'csv') implements ToArray, WithCustomCsvSettings, WithLimit, WithReadFilter {
            // CSV ถูกจำกัดด้วยขนาดไฟล์ 2 MB อยู่แล้ว จำกัดแถว/คอลัมน์เฉพาะ Excel ที่ขยายตัวได้มาก
            public function __construct(private string $delimiter, private bool $bounded)
            {
            }

            public function array(array $array): void
            {
            }

            public function limit(): int
            {
                return $this->bounded ? EmailImport::READ_ROW_LIMIT : PHP_INT_MAX;
            }

            // อ่านเฉพาะแถว/คอลัมน์ที่จำเป็น ไฟล์เล็กที่ขยายเป็นหลายล้านเซลล์จะได้ไม่กินหน่วยความจำ
            public function readFilter(): IReadFilter
            {
                return new class($this->bounded) implements IReadFilter {
                    public function __construct(private bool $bounded)
                    {
                    }

                    public function readCell($columnAddress, $row, $worksheetName = ''): bool
                    {
                        return ! $this->bounded || $row <= EmailImport::READ_ROW_LIMIT
                            && Coordinate::columnIndexFromString($columnAddress) <= EmailImport::READ_COLUMN_LIMIT;
                    }
                };
            }

            public function getCsvSettings(): array
            {
                return ['delimiter' => $this->delimiter, 'input_encoding' => 'UTF-8'];
            }
        };

        try {
            // ส่งเป็น UploadedFile (โหมดทดสอบ) เพื่อให้อ่านจาก path จริงโดยไม่ผ่าน disk
            $sheets = app('excel')->toArray($import, new UploadedFile($path, 'import.' . $extension, null, null, true), null, $type);
        } catch (\Throwable $e) {
            // บันทึกเฉพาะชนิดและข้อความของข้อผิดพลาด (ไม่บันทึกข้อมูลในไฟล์) เพื่อให้ตามสาเหตุได้
            \Illuminate\Support\Facades\Log::warning('Email import: cannot read file', ['error' => get_class($e) . ': ' . $e->getMessage()]);

            throw new EmailImportException('อ่านไฟล์ไม่ได้ กรุณาตรวจสอบว่าไฟล์ไม่เสียหายและเป็นรูปแบบที่ถูกต้อง');
        }

        return $this->parse($sheets[0] ?? [], $extension !== 'csv');
    }

    /**
     * @return list<array{row:int,username:string,email:string}>
     */
    private function readCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            throw new EmailImportException('อ่านไฟล์ไม่ได้ กรุณาตรวจสอบว่าไฟล์ไม่เสียหายและเป็นรูปแบบที่ถูกต้อง');
        }

        $tooMany = fn() => new EmailImportException('ไฟล์มีข้อมูลเกิน ' . number_format(self::MAX_ROWS) . ' แถว กรุณาแบ่งไฟล์');

        try {
            $delimiter = $this->sniffDelimiter($path);
            $rows = [];
            $line = 0;
            $blankRun = 0;
            $usernameCol = $emailCol = false;

            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;

                if ($line > self::CSV_MAX_LINES) {
                    throw $tooMany();
                }

                if ($line === 1) {
                    $header = array_map(fn($cell) => mb_strtolower($this->cell($cell)), $cells);
                    $usernameCol = array_search('username', $header, true);
                    $emailCol = array_search('email', $header, true);

                    if ($usernameCol === false || $emailCol === false) {
                        throw new EmailImportException('ไม่พบคอลัมน์ username และ email ในแถวแรกของไฟล์');
                    }

                    continue;
                }

                $username = $this->cell($cells[$usernameCol] ?? null);
                $email = $this->cell($cells[$emailCol] ?? null);

                if ($username === '' && $email === '') {
                    if (++$blankRun > self::CSV_MAX_BLANK_RUN) {
                        throw $tooMany();
                    }

                    continue;
                }

                $blankRun = 0;

                if (count($rows) >= self::MAX_ROWS) {
                    throw $tooMany();
                }

                $rows[] = ['row' => $line, 'username' => $username, 'email' => $email];
            }

            if ($line === 0 || $usernameCol === false || $emailCol === false) {
                throw new EmailImportException('ไม่พบคอลัมน์ username และ email ในแถวแรกของไฟล์');
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, array<int, mixed>>  $matrix  แถวดิบ (แถวแรกคือหัวตาราง)
     * @return list<array{row:int,username:string,email:string}>
     */
    public function parse(array $matrix, bool $bounded = false): array
    {
        $matrix = array_values($matrix);

        // Excel ถูกตัดที่ READ_ROW_LIMIT แถว ถ้าถึงขีดนี้แสดงว่าไฟล์ยาวเกินและอาจถูกตัดท้าย จึงไม่ประมวลผล
        if ($bounded && count($matrix) >= self::READ_ROW_LIMIT) {
            throw new EmailImportException('ไฟล์มีข้อมูลเกิน ' . number_format(self::MAX_ROWS) . ' แถว กรุณาแบ่งไฟล์');
        }
        $header = array_map(fn($cell) => mb_strtolower($this->cell($cell)), $matrix[0] ?? []);

        $usernameCol = array_search('username', $header, true);
        $emailCol = array_search('email', $header, true);

        if ($usernameCol === false || $emailCol === false) {
            throw new EmailImportException('ไม่พบคอลัมน์ username และ email ในแถวแรกของไฟล์');
        }

        $rows = [];
        foreach (array_slice($matrix, 1, null, true) as $index => $cells) {
            $username = $this->cell($cells[$usernameCol] ?? null);
            $email = $this->cell($cells[$emailCol] ?? null);

            if ($username === '' && $email === '') {
                continue;
            }

            $rows[] = ['row' => $index + 1, 'username' => $username, 'email' => $email];
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new EmailImportException('ไฟล์มีข้อมูลเกิน ' . number_format(self::MAX_ROWS) . ' แถว กรุณาแบ่งไฟล์');
        }

        return $rows;
    }

    /**
     * ตรวจทุกแถวโดยไม่เขียนฐานข้อมูล
     *
     * @param  list<array{row:int,username:string,email:string}>  $rows
     */
    public function analyse(array $rows, bool $overwrite, User $actor, bool $lock = false): EmailImportResult
    {
        $result = new EmailImportResult;
        $actorIsSuperAdmin = $actor->hasRole(RoleEnum::SUPERADMIN);

        $rows = array_map(fn($r) => [
            'row' => (int) $r['row'],
            'username' => trim((string) $r['username']),
            'email' => trim((string) $r['email']),
        ], $rows);

        $users = $this->usersByUsername(array_column($rows, 'username'), $lock);

        $emailCount = [];
        $usernameCount = [];
        foreach ($rows as $r) {
            if ($r['email'] !== '' && $this->validFormat($r['email'])) {
                $key = mb_strtolower($r['email']);
                $emailCount[$key] = ($emailCount[$key] ?? 0) + 1;
            }
            if ($r['username'] !== '') {
                $key = mb_strtolower($r['username']);
                $usernameCount[$key] = ($usernameCount[$key] ?? 0) + 1;
            }
        }

        // อีเมลที่รับเข้ารายการอัปเดตแล้วในรอบนี้ (ใช้จับอีเมลที่ฐานข้อมูลมองว่าเท่ากัน เช่น jose/josé)
        $accepted = [];

        foreach ($rows as $r) {
            $skip = function (string $reason) use ($r, $result) {
                $result->skipped[] = [
                    'row' => $r['row'],
                    'username' => $r['username'],
                    'email' => $r['email'],
                    'reason' => $reason,
                ];
            };

            if ($r['username'] === '') {
                $skip('ไม่มี username');
                continue;
            }

            $user = $users[mb_strtolower($r['username'])] ?? null;
            if (! $user) {
                $skip('ไม่พบ username นี้');
                continue;
            }

            if ($r['email'] === '') {
                $skip('ไม่มีอีเมล');
                continue;
            }

            if (! $this->validFormat($r['email'])) {
                $skip('รูปแบบอีเมลไม่ถูกต้อง');
                continue;
            }

            $emailKey = mb_strtolower($r['email']);

            if ($emailCount[$emailKey] > 1) {
                $skip('อีเมลซ้ำกันในไฟล์');
                continue;
            }

            if ($usernameCount[mb_strtolower($r['username'])] > 1) {
                $skip('username ซ้ำกันในไฟล์');
                continue;
            }

            if ($user->id == 1 && ! $actorIsSuperAdmin) {
                $skip('ไม่มีสิทธิ์แก้ผู้ใช้นี้');
                continue;
            }

            $entry = ['row' => $r['row'], 'user_id' => $user->id, 'username' => $r['username'], 'email' => $r['email']];

            if (filled($user->email) && $user->email === $r['email']) {
                $result->unchanged[] = $entry;
                continue;
            }

            if ($this->usedByOtherUser($r['email'], $user->id, $lock) || $this->equalsAnyInDb($r['email'], $accepted)) {
                $skip('อีเมลนี้ถูกใช้โดยผู้ใช้อื่น');
                continue;
            }

            if (filled($user->email) && ! $overwrite) {
                $skip('มีอีเมลอยู่แล้ว');
                continue;
            }

            $accepted[] = $r['email'];
            $result->toUpdate[] = $entry;
        }

        return $result;
    }

    /**
     * ตรวจซ้ำกับฐานข้อมูลปัจจุบันใน transaction เดียว (ล็อกแถวที่เกี่ยวข้อง) แล้วอัปเดตเฉพาะคอลัมน์ email
     * ถ้ามีข้อผิดพลาดใด ๆ จะไม่บันทึกอะไรเลย
     *
     * @param  list<array{row:int,username:string,email:string}>  $rows
     */
    public function apply(array $rows, bool $overwrite, User $actor): EmailImportResult
    {
        return DB::transaction(function () use ($rows, $overwrite, $actor) {
            $result = $this->analyse($rows, $overwrite, $actor, lock: true);

            $toUpdate = $result->toUpdate;
            $result->toUpdate = [];

            foreach ($toUpdate as $entry) {
                // เช็กกับฐานข้อมูลอีกครั้งก่อนบันทึกทุกแถว (ตาม collation จริง และเห็นแถวที่บันทึกไปแล้วใน transaction นี้)
                if ($this->usedByOtherUser($entry['email'], $entry['user_id'], true)) {
                    $result->skipped[] = [
                        'row' => $entry['row'],
                        'username' => $entry['username'],
                        'email' => $entry['email'],
                        'reason' => 'อีเมลนี้ถูกใช้โดยผู้ใช้อื่น',
                    ];
                    continue;
                }

                $user = User::query()->findOrFail($entry['user_id']);
                $user->forceFill(['email' => $entry['email']])->save();
                $result->toUpdate[] = $entry;
            }

            usort($result->skipped, fn($a, $b) => $a['row'] <=> $b['row']);

            return $result;
        });
    }

    /**
     * @param  list<string>  $usernames
     * @return array<string, User>
     */
    private function usersByUsername(array $usernames, bool $lock): array
    {
        $usernames = array_values(array_unique(array_filter($usernames, fn($u) => $u !== '')));
        $map = [];

        foreach (array_chunk($usernames, 500) as $chunk) {
            $query = User::query()->whereIn('username', $chunk);
            // email ไม่มี index: lockForUpdate จะล็อกทั้งตาราง users ช่วงนี้ (ยอมรับได้ที่ขนาดนี้)
            $lock && $query->lockForUpdate();

            foreach ($query->get() as $user) {
                $map[mb_strtolower(trim($user->username))] ??= $user;
            }
        }

        return $map;
    }

    /**
     * มีผู้ใช้อื่น (ที่ยังไม่ถูกลบ) ใช้อีเมลนี้อยู่หรือไม่ ตามการเทียบของฐานข้อมูลเอง (collation เช่น jose = josé)
     * หมายเหตุ: คอลัมน์ email ไม่มี index การสแกนแบบ lockForUpdate จึงล็อกตาราง users ตลอดช่วงนั้น (ยอมรับได้ที่ขนาดนี้)
     */
    private function usedByOtherUser(string $email, int $userId, bool $lock): bool
    {
        $query = User::query()->where('email', $email)->whereKeyNot($userId);
        $lock && $query->lockForUpdate();

        return $query->exists();
    }

    /**
     * อีเมลนี้เท่ากับอีเมลใดในรายการหรือไม่ ตาม collation ของฐานข้อมูล (ไม่แตะตาราง)
     *
     * @param  list<string>  $emails
     */
    private function equalsAnyInDb(string $email, array $emails): bool
    {
        foreach (array_chunk($emails, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $row = DB::selectOne("select ? in ({$placeholders}) as hit", [$email, ...$chunk]);

            if ($row && (int) $row->hit === 1) {
                return true;
            }
        }

        return false;
    }
    private function validFormat(string $email): bool
    {
        return Validator::make(['email' => $email], ['email' => ['email', 'max:255']])->passes();
    }

    private function cell(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        // ตัด BOM และ non-breaking space ที่ติดมากับหัวตาราง/เซลล์
        $text = preg_replace('/^\x{FEFF}/u', '', (string) $value) ?? (string) $value;
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim($text);
    }

    private function sniffDelimiter(string $path): string
    {
        $handle = @fopen($path, 'rb');
        $line = $handle ? (string) fgets($handle) : '';
        $handle && fclose($handle);

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }
}
