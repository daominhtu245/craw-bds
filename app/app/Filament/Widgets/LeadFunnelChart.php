<?php

namespace App\Filament\Widgets;

use App\Services\Leads\LeadStats;
use Filament\Widgets\ChartWidget;

/**
 * Funnel over the last 30 days: collected → passed prefilter → lead → contacted → won.
 */
class LeadFunnelChart extends ChartWidget
{
    protected ?string $heading = 'Phễu 30 ngày';

    protected static ?int $sort = 3;

    protected function getData(): array
    {
        $funnel = app(LeadStats::class)->funnel(now()->subDays(30));

        return [
            'datasets' => [[
                'label' => 'Số lượng',
                'data' => array_values($funnel),
                'backgroundColor' => ['#94a3b8', '#60a5fa', '#f59e0b', '#a78bfa', '#22c55e'],
            ]],
            'labels' => ['Item thu', 'Qua prefilter', 'Lead', 'Đã liên hệ', 'Thành công'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
