<?php

namespace App\Filament\Support;

use App\Models\Lead;

/**
 * Small display helpers shared by tables, infolists and widgets.
 */
final class LeadFormat
{
    public const FLAGS = ['en' => '🇬🇧', 'ru' => '🇷🇺', 'ko' => '🇰🇷', 'zh' => '🇨🇳', 'vi' => '🇻🇳'];

    public static function language(?string $code): string
    {
        $code = strtolower((string) $code);

        return (self::FLAGS[$code] ?? '🌐').' '.strtoupper($code);
    }

    public static function budget(Lead $lead): ?string
    {
        $min = $lead->budget_min_usd;
        $max = $lead->budget_max_usd;

        return match (true) {
            $min !== null && $max !== null && $min !== $max => "{$min}–{$max}$",
            $max !== null => "≤ {$max}$",
            $min !== null => "≥ {$min}$",
            default => null,
        };
    }

    public static function scoreColor(?int $score): string
    {
        return match (true) {
            $score >= 80 => 'danger',
            $score >= 60 => 'warning',
            default => 'gray',
        };
    }
}
