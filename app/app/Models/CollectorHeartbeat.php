<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollectorHeartbeat extends Model
{
    protected $fillable = ['collector', 'ok', 'last_seen_at', 'meta'];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'last_seen_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
