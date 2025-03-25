<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicUrl extends Model
{
    protected $fillable = [
        'url',
        'name',
        'publish',
        'desc',
    ];

    public function scopePublish($query)
    {
        return $query->where('publish', true);
    }
}
