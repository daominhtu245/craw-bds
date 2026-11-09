<?php

namespace App\Filament\Widgets;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Services\Leads\LeadStats;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * New leads today / 7 days and the real-world AI accuracy (SPEC 14.4).
 */
class LeadStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $stats = app(LeadStats::class);
        $invalidRate = $stats->invalidRate(30);

        return [
            Stat::make('Lead mới hôm nay', (string) $stats->leadsSince($stats->startOfTodayUtc())),
            Stat::make('Lead 7 ngày', (string) $stats->leadsSince(now()->subDays(7))),
            Stat::make('Đang chờ nhận', (string) Lead::query()->where('status', LeadStatus::New->value)->count()),
            Stat::make('Tỷ lệ invalid (30 ngày)', $invalidRate === null ? '—' : number_format($invalidRate * 100, 1).'%')
                ->description('Lead sale đánh dấu không hợp lệ')
                ->color($invalidRate !== null && $invalidRate > 0.15 ? 'danger' : 'success'),
        ];
    }
}
