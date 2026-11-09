<?php

namespace App\Services\Scoring;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Models\Lead;
use Carbon\CarbonInterface;

/**
 * Lead score 0–100 (SPEC 10). Pure: everything it needs is passed in.
 */
class LeadScorer
{
    /**
     * @param  int|null  $inventoryMin  Lowest active listing price (USD).
     * @param  int|null  $inventoryMax  Highest active listing price (USD).
     * @param  int|null  $bestMatchScore  Best listing match_score, null when none.
     */
    public function score(
        Lead $lead,
        CarbonInterface $postedAt,
        CarbonInterface $now,
        ?int $inventoryMin,
        ?int $inventoryMax,
        ?int $bestMatchScore,
    ): int {
        $w = config('leadradar.scoring');

        if ($lead->intent === LeadIntent::LeavingHandover) {
            return (int) $w['leaving_handover_score'];
        }

        $score = 0;

        if ($lead->intent === LeadIntent::TenantDemand) {
            $score += $w['tenant_demand'];
        }

        $score += match ($lead->stage) {
            LeadStage::Urgent => $w['stage_urgent'],
            LeadStage::InCitySearching => $w['stage_in_city'],
            LeadStage::PreArrival => $this->arrivesSoon($lead, $now, (int) $w['pre_arrival_window_days']) ? $w['stage_pre_arrival_soon'] : 0,
            default => 0,
        };

        $duration = $lead->duration_months;
        if ($duration !== null && $duration >= 6) {
            $score += $w['duration_6_plus'];
        } elseif ($duration !== null && $duration >= 3) {
            $score += $w['duration_3_to_5'];
        }

        if ($this->budgetInInventoryRange($lead, $inventoryMin, $inventoryMax, (float) $w['budget_tolerance'])) {
            $score += $w['budget_in_inventory_range'];
        }

        if ((array) $lead->areas !== []) {
            $score += $w['area_matched'];
        }

        if ($bestMatchScore !== null && $bestMatchScore >= $w['listing_match_min']) {
            $score += $w['listing_matched'];
        }

        if ($postedAt->greaterThan($now->copy()->subHours((int) $w['fresh_post_hours']))) {
            $score += $w['fresh_post'];
        }

        if ((float) $lead->confidence < $w['low_confidence_below']) {
            $score += $w['low_confidence_penalty'];
        }

        return max(0, min(100, $score));
    }

    private function arrivesSoon(Lead $lead, CarbonInterface $now, int $days): bool
    {
        if ($lead->arrival_date === null) {
            return false;
        }

        $arrival = $lead->arrival_date->copy()->startOfDay();

        return $arrival->gte($now->copy()->startOfDay()) && $arrival->lte($now->copy()->addDays($days)->endOfDay());
    }

    /** Budget range overlaps [inventoryMin − 15%, inventoryMax + 15%]. */
    private function budgetInInventoryRange(Lead $lead, ?int $inventoryMin, ?int $inventoryMax, float $tolerance): bool
    {
        if ($inventoryMin === null || $inventoryMax === null) {
            return false;
        }

        $low = $lead->budget_min_usd ?? $lead->budget_max_usd;
        $high = $lead->budget_max_usd ?? $lead->budget_min_usd;

        if ($low === null || $high === null) {
            return false;
        }

        return $high >= $inventoryMin * (1 - $tolerance) && $low <= $inventoryMax * (1 + $tolerance);
    }
}
