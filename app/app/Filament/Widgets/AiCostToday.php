<?php

namespace App\Filament\Widgets;

use App\Services\Leads\LeadStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Estimated AI cost today: tokens × prices in config (SPEC 14.4).
 */
class AiCostToday extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    protected function getStats(): array
    {
        $stats = app(LeadStats::class);
        $since = $stats->startOfTodayUtc();
        $usd = $stats->aiCostSince($since);
        $budget = (float) config('leadradar.ai.daily_budget_usd');
        $prefilter = $stats->prefilterRate($since);

        return [
            Stat::make('Chi phí AI hôm nay (ước tính)', '$'.number_format($usd, 3))
                ->description('Ngân sách: $'.number_format($budget, 2).'/ngày')
                ->color($usd > $budget * 0.8 ? 'danger' : 'success'),
            Stat::make('Item gọi AI hôm nay', (string) $stats->aiCallsSince($since)),
            Stat::make('Tỷ lệ lọc trước AI hôm nay', $prefilter === null ? '—' : number_format($prefilter * 100, 1).'%')
                ->description('Mục tiêu ≥ 70%'),
        ];
    }
}
