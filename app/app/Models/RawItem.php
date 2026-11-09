<?php

namespace App\Models;

use App\Enums\FilterReason;
use App\Enums\RawItemStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RawItem extends Model
{
    protected $fillable = [
        'source_id', 'external_id', 'parent_external_id', 'url', 'author_handle', 'author_key',
        'text', 'context_text', 'content_hash', 'language', 'posted_at', 'status', 'filter_reason',
        'classification', 'ai_model', 'ai_input_tokens', 'ai_output_tokens',
    ];

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'status' => RawItemStatus::class,
            'filter_reason' => FilterReason::class,
            'classification' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }
}
