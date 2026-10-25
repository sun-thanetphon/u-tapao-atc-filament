<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * อัปโหลดชุดสำรองไป Google Drive ด้วย REST API ตรงๆ (ไม่ต้องติดตั้งแพ็กเกจเพิ่มบน shared host)
 * ใช้ scope drive.file: เห็นและลบได้เฉพาะไฟล์/โฟลเดอร์ที่ระบบนี้สร้างเอง ไม่แตะไฟล์อื่นของเจ้าของไดรฟ์
 */
class GoogleDriveBackup
{
    private const API = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';
    /** ต้องเป็นผลคูณของ 256 KB ตามข้อกำหนด resumable upload */
    private const CHUNK = 8 * 1024 * 1024;

    private ?string $token = null;
    private ?string $folderId = null;

    public static function configured(): bool
    {
        $c = config('services.google_drive');

        return filled($c['client_id'] ?? null) && filled($c['client_secret'] ?? null) && filled($c['refresh_token'] ?? null);
    }

    public function upload(string $path): string
    {
        $size = filesize($path);
        $init = $this->http()
            ->withHeaders(['X-Upload-Content-Length' => $size])
            ->post(self::UPLOAD . '?uploadType=resumable&fields=id', [
                'name' => basename($path),
                'parents' => [$this->folderId()],
            ]);

        if (! $init->successful() || ! $init->header('Location')) {
            throw new RuntimeException('เริ่มอัปโหลดไป Google Drive ไม่ได้: ' . $init->status());
        }

        $url = $init->header('Location');
        $handle = fopen($path, 'rb');

        try {
            $offset = 0;
            do {
                $chunk = (string) fread($handle, self::CHUNK);
                $end = $offset + strlen($chunk) - 1;
                $range = $size === 0 ? 'bytes */0' : "bytes {$offset}-{$end}/{$size}";

                $response = $this->putChunk($url, $chunk, $range);
                $offset += strlen($chunk);
            } while ($offset < $size);
        } finally {
            fclose($handle);
        }

        if (! $response->successful()) {
            throw new RuntimeException('อัปโหลดไป Google Drive ไม่สำเร็จ: ' . $response->status());
        }

        return (string) $response->json('id');
    }

    /** เก็บไฟล์ใหม่สุด $keep ไฟล์ที่ชื่อขึ้นต้นด้วย $kind- ลบที่เหลือ @return int จำนวนที่ลบ */
    public function prune(string $kind, int $keep): int
    {
        $files = $this->http()->get(self::API . '/files', [
            'q' => sprintf("'%s' in parents and name contains '%s-' and trashed = false", $this->folderId(), $kind),
            'orderBy' => 'createdTime desc',
            'fields' => 'files(id)',
            'pageSize' => 200,
        ])->throw()->json('files', []);

        $deleted = 0;
        foreach (array_slice($files, max($keep, 1)) as $file) {
            if ($this->http()->delete(self::API . '/files/' . $file['id'])->successful()) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function putChunk(string $url, string $chunk, string $range)
    {
        $attempt = 0;

        do {
            $response = Http::withToken($this->accessToken())
                ->withHeaders(['Content-Range' => $range])
                ->withBody($chunk, 'application/octet-stream')
                ->timeout(300)
                ->put($url);

            // 308 = รับก้อนนี้แล้ว รอก้อนต่อไป
            if ($response->successful() || $response->status() === 308) {
                return $response;
            }
        } while ($response->serverError() && ++$attempt < 3 && sleep(2) === 0);

        throw new RuntimeException('อัปโหลดไป Google Drive ไม่สำเร็จ: ' . $response->status());
    }

    private function folderId(): string
    {
        if ($this->folderId) {
            return $this->folderId;
        }

        $name = config('services.google_drive.folder_name');
        $found = $this->http()->get(self::API . '/files', [
            'q' => sprintf("name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false", str_replace("'", "\\'", $name)),
            'fields' => 'files(id)',
        ])->throw()->json('files.0.id');

        return $this->folderId = $found ?: $this->http()->post(self::API . '/files?fields=id', [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ])->throw()->json('id');
    }

    private function http()
    {
        return Http::withToken($this->accessToken())->acceptJson()->timeout(60);
    }

    private function accessToken(): string
    {
        if ($this->token) {
            return $this->token;
        }

        $c = config('services.google_drive');
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'refresh_token' => $c['refresh_token'],
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('ขอ access token จาก Google ไม่สำเร็จ (refresh token หมดอายุหรือถูกเพิกถอน?): ' . $response->json('error', $response->status()));
        }

        return $this->token = $response->json('access_token');
    }
}
