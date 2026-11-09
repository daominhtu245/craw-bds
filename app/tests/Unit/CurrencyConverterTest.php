<?php

namespace Tests\Unit;

use App\Services\Currency\CurrencyConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurrencyConverterTest extends TestCase
{
    private function converter(): CurrencyConverter
    {
        return new CurrencyConverter(['VND' => 26000, 'RUB' => 80, 'EUR' => 0.92, 'KRW' => 1380, 'AUD' => 1.53, 'GBP' => 0.79]);
    }

    public static function ranges(): array
    {
        return [
            'usd month' => [null, 450, 'USD', 'month', null, [null, 450]],
            'vnd month range' => [13_000_000, 18_200_000, 'VND', 'month', 6, [500, 700]],
            'rub month' => [40_000, null, 'RUB', 'month', null, [500, null]],
            'eur month' => [null, 460, 'EUR', 'month', 12, [null, 500]],
            'krw month' => [null, 690_000, 'KRW', 'month', null, [null, 500]],
            'total spread over duration' => [null, 3000, 'USD', 'total', 6, [null, 500]],
            'total vnd spread' => [null, 78_000_000, 'VND', 'total', 6, [null, 500]],
            'total without duration' => [null, 3000, 'USD', 'total', null, [null, null]],
            'unknown period treated monthly' => [null, 600, 'USD', 'unknown', null, [null, 600]],
            'other currency' => [null, 500, 'OTHER', 'month', null, [null, null]],
            'swapped min max' => [700, 500, 'USD', 'month', null, [500, 700]],
            'lowercase currency' => [null, 26_000_000, 'vnd', 'month', null, [null, 1000]],
        ];
    }

    #[DataProvider('ranges')]
    public function test_monthly_usd_range(?float $min, ?float $max, string $currency, string $period, ?int $duration, array $expected): void
    {
        $this->assertSame($expected, $this->converter()->monthlyUsdRange($min, $max, $currency, $period, $duration));
    }

    public function test_to_usd(): void
    {
        $this->assertSame(100.0, $this->converter()->toUsd(100, 'USD'));
        $this->assertEqualsWithDelta(126.58, $this->converter()->toUsd(100, 'GBP'), 0.01);
        $this->assertNull($this->converter()->toUsd(100, 'THB'));
    }
}
