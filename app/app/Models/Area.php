<?php

namespace App\Models;

use App\Services\Areas\AreaResolver;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $fillable = ['slug', 'name_en', 'name_vi', 'aliases'];

    protected static function booted(): void
    {
        static::saved(fn () => AreaResolver::flush());
        static::deleted(fn () => AreaResolver::flush());
    }

    protected function casts(): array
    {
        return ['aliases' => 'array'];
    }
}
