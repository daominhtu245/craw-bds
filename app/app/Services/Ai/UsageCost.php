<?php

namespace App\Services\Ai;

/**
 * Token cost estimate from config('leadradar.ai.pricing') (USD per 1M tokens).
 */
class UsageCost
{
    public function usd(?string $model, ?int $inputTokens, ?int $outputTokens): float
    {
        $pricing = config('leadradar.ai.pricing.'.$model);

        if (! is_array($pricing)) {
            return 0.0;
        }

        return ((int) $inputTokens * $pricing['input'] + (int) $outputTokens * $pricing['output']) / 1_000_000;
    }
}
