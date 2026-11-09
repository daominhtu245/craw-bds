<?php

namespace App\Filament\Widgets;

use App\Services\Leads\LeadStats;
use Filament\Widgets\ChartWidget;

/**
 * New leads in 7 days by source type or by language (SPEC 14.4).
 */
class LeadsBreakdownChart extends ChartWidget
{
    protected ?string $heading = 'Lead 7 ngày';

    protected static ?int $sort = 4;

    public ?string $filter = 'source';

    protected function getFilters(): ?array
    {
        return ['source' => 'Theo nguồn', 'language' => 'Theo ngôn ngữ'];
    }

    protected function getData(): array
    {
        $stats = app(LeadStats::class);
        $rows = $this->filter === 'language'
            ? $stats->leadsByLanguage(now()->subDays(7))
            : $stats->leadsBySourceType(now()->subDays(7));

        return [
            'datasets' => [[
                'label' => 'Lead',
                'data' => array_values($rows),
                'backgroundColor' => ['#f59e0b', '#3b82f6', '#22c55e', '#ef4444', '#a855f7', '#64748b'],
            ]],
            'labels' => array_map('strval', array_keys($rows)),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
