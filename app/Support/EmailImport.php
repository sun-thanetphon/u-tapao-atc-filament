<?php

namespace App\Support;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Excel;

/**
 * นำเข้าอีเมลผู้ใช้จำนวนมากจากไฟล์ CSV/Excel โดยจับคู่ด้วย username เท่านั้น
 * แยกเป็น 3 ขั้น: readFile (อ่านไฟล์) -> analyse (ตรวจ ไม่เขียนฐานข้อมูล) -> apply (ตรวจซ้ำใน transaction แล้วเขียน)
 */
class EmailImport
{
    public const MAX_ROWS = 1000;

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

        $import = new class($extension === 'csv' ? $this->sniffDelimiter($path) : ',') implements ToArray, WithCustomCsvSettings {
            public function __construct(private string $delimiter)
            {
            }

            public function array(array $array): void
            {
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
            throw new EmailImportException('อ่านไฟล์ไม่ได้ กรุณาตรวจสอบว่าไฟล์ไม่เสียหายและเป็นรูปแบบที่ถูกต้อง');
        }

        return $this->parse($sheets[0] ?? []);
    }

    /**
     * @param  array<int, array<int, mixed>>  $matrix  แถวดิบ (แถวแรกคือหัวตาราง)
     * @return list<array{row:int,username:string,email:string}>
     */
    public function parse(array $matrix): array
    {
        $matrix = array_values($matrix);
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

        $owners = $this->emailOwners(array_keys($emailCount), $lock);

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

            if (array_diff($owners[$emailKey] ?? [], [$user->id]) !== []) {
                $skip('อีเมลนี้ถูกใช้โดยผู้ใช้อื่น');
                continue;
            }

            if (filled($user->email) && ! $overwrite) {
                $skip('มีอีเมลอยู่แล้ว');
                continue;
            }

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

            foreach ($result->toUpdate as $entry) {
                $user = User::query()->findOrFail($entry['user_id']);
                $user->forceFill(['email' => $entry['email']])->save();
            }

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
            $lock && $query->lockForUpdate();

            foreach ($query->get() as $user) {
                $map[mb_strtolower(trim($user->username))] ??= $user;
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $emails  ตัวพิมพ์เล็ก
     * @return array<string, list<int>> อีเมล (ตัวพิมพ์เล็ก) => id ผู้ใช้ที่ยังไม่ถูกลบซึ่งใช้อีเมลนั้นอยู่
     */
    private function emailOwners(array $emails, bool $lock): array
    {
        $owners = [];

        foreach (array_chunk($emails, 500) as $chunk) {
            $query = User::query()->whereIn('email', $chunk);
            $lock && $query->lockForUpdate();

            foreach ($query->get(['id', 'email']) as $user) {
                $owners[mb_strtolower(trim($user->email))][] = $user->id;
            }
        }

        return $owners;
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
