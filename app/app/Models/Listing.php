<?php

namespace App\Models;

use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Listing extends Model
{
    public const LOCALES = ['vi', 'en', 'ru'];

    protected $fillable = [
        'project_id', 'title', 'slug', 'area_slug', 'property_type', 'bedrooms', 'size_m2', 'price_usd', 'min_term_months',
        'pet_friendly', 'furnished', 'features', 'available_from', 'url', 'is_active', 'is_public', 'photos', 'content',
    ];

    protected static function booted(): void
    {
        // Public listings need a stable slug for their URL.
        static::saved(function (Listing $listing) {
            if (blank($listing->slug)) {
                $listing->slug = Str::slug($listing->title).'-'.$listing->id;
                $listing->saveQuietly();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'pet_friendly' => 'boolean',
            'furnished' => 'boolean',
            'features' => 'array',
            'available_from' => 'date',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'photos' => 'array',
            'content' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class, 'area_slug', 'slug');
    }

    /** Shown on the public site: for rent, marked public, has a slug. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true)->whereNotNull('slug');
    }

    /** Text for one locale, falling back to English, then Vietnamese, then the internal title. */
    public function text(string $field, string $locale): ?string
    {
        $content = (array) $this->content;

        foreach ([$locale, 'en', 'vi'] as $candidate) {
            $value = trim((string) ($content[$candidate][$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $field === 'title' ? $this->title : null;
    }

    /** @return list<string> */
    public function photoUrls(): array
    {
        return collect((array) $this->photos)
            ->filter()
            ->map(fn (string $path) => Storage::disk('public')->url($path))
            ->values()
            ->all();
    }
}
