<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReplyDraft extends Model
{
    protected $fillable = ['lead_id', 'variant', 'language', 'body', 'listing_ids', 'model', 'created_by'];

    protected function casts(): array
    {
        return ['listing_ids' => 'array'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
