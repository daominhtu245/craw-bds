<?php

namespace App\Models;

use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Model;

class Listing extends Model
{
    protected $fillable = [
        'title', 'area_slug', 'property_type', 'bedrooms', 'price_usd', 'min_term_months',
        'pet_friendly', 'furnished', 'features', 'available_from', 'url', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'pet_friendly' => 'boolean',
            'furnished' => 'boolean',
            'features' => 'array',
            'available_from' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
