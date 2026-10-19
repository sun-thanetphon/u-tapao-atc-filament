<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TutorialVideo extends Model
{
    public const MIME_TYPES = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    protected $fillable = [
        'title',
        'description',
        'source',
        'file_path',
        'embed_url',
        'seq',
        'publish',
        'user_id',
    ];

    protected $casts = [
        'publish' => 'boolean',
        'seq' => 'integer',
    ];

    public function scopePublish($query)
    {
        return $query->where('publish', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('seq', 'asc')
            ->orderByDesc('created_at');
    }

    public function isUpload(): bool
    {
        return $this->source === 'upload';
    }

    public function mimeType(): string
    {
        $extension = strtolower(pathinfo((string) $this->file_path, PATHINFO_EXTENSION));

        return self::MIME_TYPES[$extension] ?? 'application/octet-stream';
    }

    /**
     * ลิงก์สำหรับ iframe รับเฉพาะ YouTube และ Vimeo ที่แปลงเป็นลิงก์ฝังได้ ลิงก์อื่นไม่ฝัง
     */
    public function embedSrc(): ?string
    {
        $url = trim((string) $this->embed_url);

        if (preg_match('~^https://(?:www\.)?youtube\.com/watch\?(?:[^#]*&)?v=([\w-]{11})(?:[&#]|$)~', $url, $m)
            || preg_match('~^https://youtu\.be/([\w-]{11})(?:[?#]|$)~', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }

        if (preg_match('~^https://vimeo\.com/(\d+)(?:[/?#]|$)~', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        return null;
    }
}
