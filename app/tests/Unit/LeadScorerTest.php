<?php

namespace Tests\Unit;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Models\Lead;
use App\Services\Scoring\LeadScorer;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadScorerTest extends TestCase
{
    private const NOW = '2026-11-09 12:00:00';

    private function lead(array $attrs): Lead
    {
        return new Lead(array_merge([
            'intent' => LeadIntent::TenantDemand,
            'stage' => LeadStage::Unknown,
            'areas' => [],
            'requirements' => [],
            'confidence' => 0.9,
        ], $attrs));
    }

    public static function cases(): array
    {
        // [lead attrs, posted hours ago, best match score, expected]
        return [
            'bare tenant demand (old post)' => [[], 24, null, 10],
            'fresh post' => [[], 2, null, 20],
            'urgent' => [['stage' => LeadStage::Urgent], 24, null, 35],
            'in city' => [['stage' => LeadStage::InCitySearching], 24, null, 25],
            'pre-arrival in 30 days' => [['stage' => LeadStage::PreArrival, 'arrival_date' => '2026-12-09'], 24, null, 35],
            'pre-arrival in 90 days' => [['stage' => LeadStage::PreArrival, 'arrival_date' => '2027-02-07'], 24, null, 10],
            'pre-arrival date in past' => [['stage' => LeadStage::PreArrival, 'arrival_date' => '2026-11-01'], 24, null, 10],
            'pre-arrival unknown date' => [['stage' => LeadStage::PreArrival], 24, null, 10],
            'duration 6+' => [['duration_months' => 12], 24, null, 30],
            'duration 3-5' => [['duration_months' => 4], 24, null, 20],
            'duration 2' => [['duration_months' => 2], 24, null, 10],
            'budget in range' => [['budget_max_usd' => 450], 24, null, 25],
            'budget just under range -15%' => [['budget_max_usd' => 290], 24, null, 25],
            'budget too low' => [['budget_max_usd' => 200], 24, null, 10],
            'budget above range +15%' => [['budget_min_usd' => 1800, 'budget_max_usd' => 2500], 24, null, 10],
            'area matched' => [['areas' => ['an-thuong']], 24, null, 20],
            'listing match 60' => [[], 24, 60, 20],
            'listing match 59' => [[], 24, 59, 10],
            'low confidence' => [['confidence' => 0.7], 24, null, 0],
            'max lead' => [[
                'stage' => LeadStage::Urgent, 'duration_months' => 6, 'budget_max_usd' => 450,
                'areas' => ['an-thuong'], 'confidence' => 0.95,
            ], 1, 85, 100],
            'leaving handover fixed' => [['intent' => LeadIntent::LeavingHandover, 'stage' => LeadStage::Urgent, 'duration_months' => 12], 1, 90, 50],
        ];
    }

    #[DataProvider('cases')]
    public function test_score(array $attrs, int $hoursAgo, ?int $bestMatch, int $expected): void
    {
        $now = Carbon::parse(self::NOW);

        $score = (new LeadScorer)->score(
            $this->lead($attrs),
            $now->copy()->subHours($hoursAgo),
            $now,
            330,   // inventory min
            1500,  // inventory max
            $bestMatch,
        );

        $this->assertSame($expected, $score);
    }

    public function test_score_is_clamped_to_zero(): void
    {
        $now = Carbon::parse(self::NOW);
        $lead = $this->lead(['intent' => LeadIntent::TenantDemand, 'confidence' => 0.6]);
        config(['leadradar.scoring.tenant_demand' => 0]);

        $this->assertSame(0, (new LeadScorer)->score($lead, $now->copy()->subDays(2), $now, null, null, null));
    }
}
