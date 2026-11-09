<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Hard daily AI budget (SPEC 16 / 17: AI_DAILY_BUDGET_USD).
 *
 * Every successful API call adds its estimated cost to a per-day counter
 * (day = display timezone). AI jobs check exceeded() before calling the API
 * and pause themselves when the budget is used up.
 */
class AiBudget
{
    private const MICRO = 1_000_000;

    public function __construct(private readonly UsageCost $cost) {}

    public function record(string $model, int $inputTokens, int $outputTokens): void
    {
        $micro = (int) round($this->cost->usd($model, $inputTokens, $outputTokens) * self::MICRO);
        if ($micro <= 0) {
            return;
        }

        $key = $this->key();
        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key, $micro);
    }

    public function spentToday(): float
    {
        return ((int) Cache::get($this->key(), 0)) / self::MICRO;
    }

    public function limit(): float
    {
        return (float) config('leadradar.ai.daily_budget_usd');
    }

    public function exceeded(): bool
    {
        return $this->limit() > 0 && $this->spentToday() >= $this->limit();
    }

    /** Seconds until the budget resets (local midnight), at least 60. */
    public function secondsUntilReset(): int
    {
        $tz = config('leadradar.display_timezone');

        return max(60, (int) now($tz)->diffInSeconds(now($tz)->addDay()->startOfDay(), true));
    }

    private function key(): string
    {
        return 'leadradar:ai-spend:'.now(config('leadradar.display_timezone'))->toDateString();
    }
}
