<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * สำรองฐานข้อมูล (SQL บีบอัด) และไฟล์เอกสารใน storage ลงโฟลเดอร์ storage/app/backups
 * เขียนด้วย PHP ล้วน ไม่พึ่ง mysqldump เพราะ shared host อาจไม่มี
 */
class BackupService
{
    /** โฟลเดอร์ใน disk local ที่ไม่ต้องสำรอง (วิดีโอใหญ่ต้องสั่ง --with-videos เอง) */
    private const SKIP_DIRS = ['backups', 'livewire-tmp', 'tutorials'];

    public function directory(): string
    {
        return storage_path('app/backups');
    }

    /** @return string path ของไฟล์ .sql.gz */
    public function dumpDatabase(): string
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new RuntimeException('สำรองฐานข้อมูลได้เฉพาะ MySQL');
        }

        $path = $this->newPath('db', 'sql.gz');
        $gz = gzopen($path, 'wb9');

        if ($gz === false) {
            throw new RuntimeException('สร้างไฟล์สำรองไม่ได้');
        }

        $pdo = DB::connection()->getPdo();

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(\PDO::FETCH_NUM) as [$table]) {
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $create = $pdo->query("SHOW CREATE TABLE {$quoted}")->fetch(\PDO::FETCH_NUM)[1];

            gzwrite($gz, "DROP TABLE IF EXISTS {$quoted};\n{$create};\n\n");

            $stmt = $pdo->query("SELECT * FROM {$quoted}", \PDO::FETCH_NUM);
            $batch = [];

            while (($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                $batch[] = '(' . implode(',', array_map(
                    fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    $row
                )) . ')';

                if (count($batch) >= 200) {
                    gzwrite($gz, "INSERT INTO {$quoted} VALUES " . implode(',', $batch) . ";\n");
                    $batch = [];
                }
            }

            if ($batch !== []) {
                gzwrite($gz, "INSERT INTO {$quoted} VALUES " . implode(',', $batch) . ";\n");
            }

            gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);

        return $path;
    }

    /** @return string path ของไฟล์ .zip */
    public function zipFiles(bool $withVideos = false): string
    {
        $root = Storage::disk('local')->path('');
        $path = $this->newPath('files', 'zip');
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('สร้างไฟล์ zip ไม่ได้');
        }

        $skip = $withVideos ? array_diff(self::SKIP_DIRS, ['tutorials']) : self::SKIP_DIRS;
        $added = 0;

        if (is_dir($root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(rtrim($root, '/'), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $relative = ltrim(substr($file->getPathname(), strlen(rtrim($root, '/'))), '/');

                if (in_array(explode('/', $relative)[0], $skip, true) || $relative === '.gitignore') {
                    continue;
                }

                $zip->addFile($file->getPathname(), $relative);
                $added++;
            }
        }

        // zip ว่างเปล่าไม่ถูกเขียนลงดิสก์ จึงใส่ไฟล์บอกไว้ให้ได้ไฟล์ที่เปิดได้เสมอ
        $zip->addFromString('MANIFEST.txt', 'files: ' . $added . "\ncreated: " . now()->toDateTimeString() . "\n");
        $zip->close();

        return $path;
    }

    /** เก็บไฟล์ใหม่สุด $keep ไฟล์ของแต่ละชนิด ลบที่เหลือ @return int จำนวนที่ลบ */
    public function prune(string $kind, int $keep): int
    {
        $files = glob($this->directory() . "/{$kind}-*") ?: [];
        rsort($files); // ชื่อมีวันเวลานำหน้า เรียงจากใหม่ไปเก่า
        $deleted = 0;

        foreach (array_slice($files, max($keep, 1)) as $old) {
            if (@unlink($old)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** คัดลอกไปไว้อีก disk (เช่น ที่เก็บนอกเครื่อง host) */
    public function copyTo(string $disk, string $path): void
    {
        $stream = fopen($path, 'rb');
        Storage::disk($disk)->put('utapao-atc-backups/' . basename($path), $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    private function newPath(string $kind, string $extension): string
    {
        if (! is_dir($this->directory()) && ! mkdir($this->directory(), 0775, true) && ! is_dir($this->directory())) {
            throw new RuntimeException('สร้างโฟลเดอร์สำรองไม่ได้');
        }

        return $this->directory() . "/{$kind}-" . now()->format('Ymd-His') . ".{$extension}";
    }
}
