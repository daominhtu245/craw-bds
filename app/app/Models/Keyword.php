<?php

namespace App\Models;

use App\Enums\KeywordKind;
use App\Services\Prefilter\KeywordRepository;
use Illuminate\Database\Eloquent\Model;

class Keyword extends Model
{
    protected $fillable = ['language', 'kind', 'term', 'weight', 'is_regex', 'is_active'];

    protected static function booted(): void
    {
        static::saved(fn () => KeywordRepository::flush());
        static::deleted(fn () => KeywordRepository::flush());
    }

    protected function casts(): array
    {
        return [
            'kind' => KeywordKind::class,
            'weight' => 'integer',
            'is_regex' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
