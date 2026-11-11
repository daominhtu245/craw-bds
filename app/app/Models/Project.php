<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A building / development shown on the public site (e.g. Masteri).
 */
class Project extends Model
{
    protected $fillable = ['slug', 'name', 'area_slug', 'description', 'is_active'];

    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            if (blank($project->slug)) {
                $project->slug = Str::slug($project->name);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'description' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function localizedDescription(string $locale): ?string
    {
        $texts = (array) $this->description;

        return ($texts[$locale] ?? null) ?: ($texts['en'] ?? null) ?: ($texts['vi'] ?? null);
    }
}
