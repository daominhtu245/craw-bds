<?php

namespace App\Models;

use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $fillable = [
        'type', 'name', 'identifier', 'language_hint', 'is_active', 'rules_note', 'last_item_at', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'is_active' => 'boolean',
            'last_item_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function rawItems(): HasMany
    {
        return $this->hasMany(RawItem::class);
    }
}
