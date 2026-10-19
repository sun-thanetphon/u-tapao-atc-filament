<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialReminder extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'item', 'expires_on', 'threshold'];

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
