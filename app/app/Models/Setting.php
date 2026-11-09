<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key-value settings editable from the dashboard (FX rates, thresholds, agency info).
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->first()?->value ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
