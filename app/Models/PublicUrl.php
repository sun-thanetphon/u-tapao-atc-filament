<?php

namespace App\Models;

use App\Enums\PublicUrlCategory;
use App\Enums\PublicUrlCategoryEnum;
use Illuminate\Database\Eloquent\Model;

class PublicUrl extends Model
{
    protected $fillable = [
        'url',
        'name',
        'publish',
        'desc',
        'category',
        'seq',
    ];

    protected $casts = [
        'publish' => 'boolean',
        'category' => PublicUrlCategoryEnum::class,
        'seq' => 'integer',
    ];

    public function scopePublish($query)
    {
        return $query->where('publish', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderByRaw('seq IS NULL, seq ASC');
    }
}
