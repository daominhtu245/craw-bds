<?php

namespace App\Services\Matching;

use App\Models\Lead;
use App\Models\Listing;
use Illuminate\Support\Collection;

/**
 * Scores active listings against a lead (SPEC 11). score() is pure.
 */
class ListingMatcher
{
    /**
     * @param  Collection<int, Listing>|null  $listings  Defaults to all active listings.
     * @return Collection<int, array{listing: Listing, score: int}> Best first, at most max_results, score >= min_score.
     */
    public function match(Lead $lead, ?Collection $listings = null): Collection
    {
        $listings ??= Listing::query()->where('is_active', true)->get();
        $config = config('leadradar.matching');

        return $listings
            ->map(fn (Listing $listing) => ['listing' => $listing, 'score' => $this->score($lead, $listing)])
            ->filter(fn (array $m) => $m['score'] >= $config['min_score'])
            ->sortByDesc('score')
            ->take($config['max_results'])
            ->values();
    }

    /** Persist matches for a lead, replacing earlier ones. */
    public function sync(Lead $lead, Collection $matches): void
    {
        $lead->matchedListings()->sync(
            $matches->mapWithKeys(fn (array $m) => [$m['listing']->id => ['match_score' => $m['score']]])->all(),
        );
    }

    public function score(Lead $lead, Listing $listing): int
    {
        $c = config('leadradar.matching');
        $score = 0;

        $areas = (array) $lead->areas;
        if ($areas === []) {
            $score += $c['area_unspecified'];
        } elseif (in_array($listing->area_slug, $areas, true)) {
            $score += $c['area_match'];
        }

        if ($lead->budget_max_usd !== null) {
            if ($listing->price_usd <= $lead->budget_max_usd) {
                $score += $c['price_within_budget'];
            } elseif ($lead->budget_max_usd > 0 && ($listing->price_usd - $lead->budget_max_usd) / $lead->budget_max_usd < $c['price_over_tolerance']) {
                $score += $c['price_slightly_over'];
            }
        }

        $bedroomsOk = $lead->bedrooms_min !== null && $listing->bedrooms >= $lead->bedrooms_min;
        if ($bedroomsOk || $listing->property_type === $lead->property_type) {
            $score += $c['bedrooms_or_type'];
        }

        if ($lead->duration_months === null || $listing->min_term_months <= $lead->duration_months) {
            $score += $c['term_ok'];
        }

        $score += min($c['requirement_max'], $this->satisfiedRequirements($lead, $listing) * $c['requirement_each']);

        return min(100, $score);
    }

    private function satisfiedRequirements(Lead $lead, Listing $listing): int
    {
        $features = (array) $listing->features;

        return collect((array) $lead->requirements)->filter(fn (string $tag) => match ($tag) {
            'pet_friendly' => $listing->pet_friendly,
            'furnished' => $listing->furnished,
            default => in_array($tag, $features, true),
        })->count();
    }
}
