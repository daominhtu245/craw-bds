<?php

namespace App\Jobs\Concerns;

use App\Services\Ai\AiBudget;
use App\Services\Ops\Alerter;
use Illuminate\Support\Facades\Log;

/**
 * Pauses AI jobs once the daily budget is spent (SPEC 16: AI_DAILY_BUDGET_USD).
 * The job is released back to the queue and re-checked every 30 minutes; the
 * team gets one alert per day.
 */
trait RespectsAiBudget
{
    /** @return bool true when the job was paused and must stop */
    protected function pauseIfOverBudget(): bool
    {
        $budget = app(AiBudget::class);

        if (! $budget->exceeded()) {
            return false;
        }

        $day = now(config('leadradar.display_timezone'))->toDateString();
        app(Alerter::class)->raise(
            "ai-budget:{$day}",
            sprintf('Đã dùng hết ngân sách AI hôm nay ($%.2f / $%.2f). Hàng đợi AI tạm dừng đến 0h.', $budget->spentToday(), $budget->limit()),
        );

        Log::warning('ai.budget_exceeded', ['job' => class_basename($this), 'spent' => $budget->spentToday()]);
        $this->release(min(1800, $budget->secondsUntilReset()));

        return true;
    }
}
