<?php

namespace Tests\Unit;

use App\Enums\PropertyType;
use App\Models\Lead;
use App\Models\Listing;
use App\Services\Matching\ListingMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListingMatcherTest extends TestCase
{
    private function listing(array $attrs = []): Listing
    {
        $listing = new Listing(array_merge([
            'title' => 'Sea Breeze 1BR',
            'area_slug' => 'an-thuong',
            'property_type' => PropertyType::OneBr,
            'bedrooms' => 1,
            'price_usd' => 420,
            'min_term_months' => 3,
            'pet_friendly' => true,
            'furnished' => true,
            'features' => ['kitchen', 'fast_wifi', 'near_beach'],
            'is_active' => true,
        ], $attrs));
        $listing->id = $attrs['id'] ?? 1;

        return $listing;
    }

    private function lead(array $attrs = []): Lead
    {
        return new Lead(array_merge([
            'areas' => ['an-thuong'],
            'property_type' => PropertyType::OneBr,
            'bedrooms_min' => 1,
            'budget_max_usd' => 450,
            'duration_months' => 6,
            'requirements' => [],
        ], $attrs));
    }

    public static function cases(): array
    {
        // area 30 + price 25 + bedrooms 20 + term 10 = 85 baseline
        return [
            'perfect' => [[], [], 85],
            'requirements capped at 15' => [['requirements' => ['pet_friendly', 'furnished', 'kitchen', 'fast_wifi']], [], 100],
            'one requirement' => [['requirements' => ['pet_friendly']], [], 90],
            'unsatisfied requirement' => [['requirements' => ['pool']], [], 85],
            'pet requirement not met' => [['requirements' => ['pet_friendly']], ['pet_friendly' => false], 85],
            'other area' => [[], ['area_slug' => 'son-tra'], 55],
            'no area given' => [['areas' => []], ['area_slug' => 'son-tra'], 70],
            'price 5% over' => [[], ['price_usd' => 470], 70],
            'price 10% over' => [[], ['price_usd' => 495], 60],
            'no budget' => [['budget_max_usd' => null], [], 60],
            'too few bedrooms but same type' => [['bedrooms_min' => 2], [], 85],
            'too few bedrooms other type' => [['bedrooms_min' => 2, 'property_type' => PropertyType::TwoBr], [], 65],
            'bigger listing ok' => [['property_type' => PropertyType::Studio, 'bedrooms_min' => 0], ['bedrooms' => 2, 'property_type' => PropertyType::TwoBr], 85],
            'term too long' => [[], ['min_term_months' => 12], 75],
            'duration unknown' => [['duration_months' => null], ['min_term_months' => 12], 85],
        ];
    }

    #[DataProvider('cases')]
    public function test_score(array $leadAttrs, array $listingAttrs, int $expected): void
    {
        $this->assertSame($expected, (new ListingMatcher)->score($this->lead($leadAttrs), $this->listing($listingAttrs)));
    }

    public function test_match_returns_top_five_above_40_sorted(): void
    {
        $listings = collect(range(1, 8))->map(fn ($i) => $this->listing([
            'id' => $i,
            'title' => "L{$i}",
            'price_usd' => 400 + $i * 10,                       // 410 … 480
            'area_slug' => $i === 8 ? 'son-tra' : 'an-thuong',
        ]));
        $listings->push($this->listing(['id' => 9, 'area_slug' => 'hoi-an', 'price_usd' => 2000, 'min_term_months' => 24, 'property_type' => PropertyType::Villa, 'bedrooms' => 0]));

        $matches = (new ListingMatcher)->match($this->lead(), $listings);

        $this->assertCount(5, $matches);
        $this->assertSame([85, 85, 85, 85], $matches->take(4)->pluck('score')->all());
        $this->assertTrue($matches->every(fn ($m) => $m['score'] >= 40));
        $this->assertNotContains(9, $matches->map(fn ($m) => $m['listing']->id)->all());
    }
}
