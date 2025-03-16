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
}