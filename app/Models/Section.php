<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    protected $fillable = [
        'prefix',
        'name',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'section_id');
    }
}