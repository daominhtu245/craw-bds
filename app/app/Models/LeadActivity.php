<?php

namespace App\Models;

use App\Enums\ActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadActivity extends Model
{
    protected $fillable = ['lead_id', 'user_id', 'type', 'body', 'meta'];

    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'meta' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
