<?php

namespace Database\Seeders;

use App\Enums\PropertyType;
use App\Models\Listing;
use Illuminate\Database\Seeder;

/**
 * Five made-up sample listings for development and matching tests.
 */
class ListingSeeder extends Seeder
{
    public function run(): void
    {
        $listings = [
            [
                'title' => 'Sea Breeze 1BR', 'area_slug' => 'an-thuong', 'property_type' => PropertyType::OneBr,
                'bedrooms' => 1, 'price_usd' => 420, 'min_term_months' => 3, 'pet_friendly' => true,
                'features' => ['kitchen', 'workspace', 'fast_wifi', 'washing_machine', 'near_beach'],
            ],
            [
                'title' => 'Han River View 2BR', 'area_slug' => 'hai-chau', 'property_type' => PropertyType::TwoBr,
                'bedrooms' => 2, 'price_usd' => 650, 'min_term_months' => 6, 'pet_friendly' => false,
                'features' => ['river_view', 'pool', 'gym', 'balcony', 'kitchen'],
            ],
            [
                'title' => 'My Khe Cozy Studio', 'area_slug' => 'my-khe', 'property_type' => PropertyType::Studio,
                'bedrooms' => 0, 'price_usd' => 330, 'min_term_months' => 1, 'pet_friendly' => false,
                'features' => ['near_beach', 'fast_wifi', 'kitchen'],
            ],
            [
                'title' => 'Son Tra Family House 3BR', 'area_slug' => 'son-tra', 'property_type' => PropertyType::House,
                'bedrooms' => 3, 'price_usd' => 900, 'min_term_months' => 12, 'pet_friendly' => true,
                'features' => ['family', 'parking', 'quiet', 'sea_view', 'washing_machine', 'kitchen'],
            ],
            [
                'title' => 'Marble Mountains Pool Villa', 'area_slug' => 'ngu-hanh-son', 'property_type' => PropertyType::Villa,
                'bedrooms' => 4, 'price_usd' => 1500, 'min_term_months' => 6, 'pet_friendly' => true,
                'features' => ['pool', 'family', 'parking', 'quiet', 'bathtub', 'kitchen'],
            ],
        ];

        foreach ($listings as $listing) {
            Listing::query()->updateOrCreate(
                ['title' => $listing['title']],
                $listing + ['furnished' => true, 'is_active' => true, 'available_from' => now()->toDateString()],
            );
        }
    }
}
