<?php

namespace App\Filament\Widgets;

use App\Enums\FilterReason;
use App\Services\Leads\LeadStats;
use Filament\Widgets\ChartWidget;

/**
 * Prefilter outcome by filter_reason over 7 days (SPEC 8).
 */
class PrefilterReasonsChart extends ChartWidget
{
    protected ?string $heading = 'Lý do lọc (7 ngày)';

    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    protected function getData(): array
    {
        $rows = app(LeadStats::class)->filterReasons(now()->subDays(7));

        return [
            'datasets' => [[
                'label' => 'Item',
                'data' => array_values($rows),
                'backgroundColor' => '#94a3b8',
            ]],
            'labels' => array_map(fn ($k) => FilterReason::tryFrom($k)?->getLabel() ?? 'Qua lọc', array_keys($rows)),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
