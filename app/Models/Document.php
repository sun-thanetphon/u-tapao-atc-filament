<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = [
        'code',
        'view_sections',
        'acknowledge_sections',
        'category_id',
        'name',
        'file_path',
        'publish',
        'creator_id',
    ];

    protected $casts = [
        'view_sections' => 'array',
        'acknowledge_sections' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'category_id');
    }

    public function acknowledges(): HasMany
    {
        return $this->hasMany(DocumentAcknowledge::class, 'document_id');
    }

    public function isAcknowledged($userId): bool //เช็คว่ารับทราบหรือยัง?
    {
        return $this->acknowledges->where('user_id', $userId)->isNotEmpty();
    }

    public function canView($sectionId): bool //อยู่ในแผนกที่เห็นเอกสารได้ไหม
    {
        return in_array($sectionId, $this->view_sections);
    }

    public function canAcknowledge($sectionId): bool //อยู่ในแผนกที่ต้องรับทราบไหม
    {
        return in_array($sectionId, $this->acknowledge_sections);
    }

    public function isNeedToAck(): bool //เป็นเอกสารที่ต้องรับทราบไหม
    {
        return !empty($this->acknowledge_sections);
    }
}
