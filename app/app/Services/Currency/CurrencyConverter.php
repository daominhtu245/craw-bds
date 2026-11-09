<?php

namespace App\Services\Currency;

use App\Models\Setting;

/**
 * Converts budgets to USD per month (SPEC 9.4 step 3).
 *
 * Rates are "units of currency per 1 USD" (settings.fx_per_usd), so
 * USD = amount / rate.
 */
class CurrencyConverter
{
    /**
     * @param  array<string, float|int>  $ratesPerUsd
     */
    public function __construct(private readonly array $ratesPerUsd) {}

    public static function fromSettings(): self
    {
        return new self((array) Setting::get('fx_per_usd', []));
    }

    public function toUsd(float $amount, string $currency): ?float
    {
        $currency = strtoupper($currency);

        if ($currency === 'USD') {
            return $amount;
        }

        $rate = (float) ($this->ratesPerUsd[$currency] ?? 0);

        return $rate > 0 ? $amount / $rate : null;
    }

    /**
     * @return array{0: ?int, 1: ?int} [min, max] in USD per month, rounded.
     *                                 Both null when the currency is unknown or a total cannot be spread.
     */
    public function monthlyUsdRange(?float $min, ?float $max, string $currency, string $period, ?int $durationMonths): array
    {
        if ($period === 'total') {
            if ($durationMonths === null || $durationMonths < 1) {
                return [null, null];
            }
            $min = $min !== null ? $min / $durationMonths : null;
            $max = $max !== null ? $max / $durationMonths : null;
        }

        $convert = function (?float $value) use ($currency): ?int {
            if ($value === null) {
                return null;
            }
            $usd = $this->toUsd($value, $currency);

            return $usd === null ? null : (int) round($usd);
        };

        $minUsd = $convert($min);
        $maxUsd = $convert($max);

        if ($minUsd !== null && $maxUsd !== null && $minUsd > $maxUsd) {
            [$minUsd, $maxUsd] = [$maxUsd, $minUsd];
        }

        return [$minUsd, $maxUsd];
    }
}
