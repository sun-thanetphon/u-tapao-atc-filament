<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentFile extends Model
{
    protected $fillable = [
        'document_id',
        'revision',
        'file_path',
        'minor',
        'note',
        'uploaded_by',
    ];

    protected $casts = [
        'revision' => 'integer',
        'minor' => 'boolean',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * ชื่อไฟล์ตอนเปิดฉบับเก่า เช่น "คู่มือ (ฉบับ 1 อัปโหลด 2026-10-28).pdf"
     */
    public function downloadName(): string
    {
        $name = substr($this->document->downloadName(), 0, -strlen('.pdf'));

        return "{$name} (ฉบับ {$this->revision} อัปโหลด {$this->created_at->format('Y-m-d')}).pdf";
    }
}
