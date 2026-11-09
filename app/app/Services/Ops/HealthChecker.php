<?php

namespace App\Services\Ops;

use App\Models\CollectorHeartbeat;
use App\Models\Source;

/**
 * Collector and source health (SPEC 13):
 * - a collector with no heartbeat for 30 minutes → alert;
 * - an active source marked important (meta.important) with no new item for 24 hours → alert.
 */
class HealthChecker
{
    public const HEARTBEAT_MAX_MINUTES = 30;

    public const SOURCE_MAX_HOURS = 24;

    public function __construct(private readonly Alerter $alerter) {}

    /** @return array{alerts: int, recoveries: int} */
    public function check(): array
    {
        $alerts = 0;
        $recoveries = 0;

        foreach (CollectorHeartbeat::query()->get() as $beat) {
            $key = "collector:{$beat->collector}";
            $stale = $beat->last_seen_at->lt(now()->subMinutes(self::HEARTBEAT_MAX_MINUTES));
            $down = $stale || ! $beat->ok;

            if ($down) {
                $why = $stale
                    ? 'không gửi heartbeat từ '.$beat->last_seen_at->copy()->setTimezone(config('leadradar.display_timezone'))->format('H:i d/m')
                    : 'báo lỗi (ok=false)';
                $alerts += (int) $this->alerter->raise($key, "Collector {$beat->collector} {$why}.");
            } else {
                $recoveries += (int) $this->alerter->resolve($key, "Collector {$beat->collector} đã hoạt động lại.");
            }
        }

        $sources = Source::query()->where('is_active', true)->get()->filter(fn (Source $s) => (bool) data_get($s->meta, 'important'));

        foreach ($sources as $source) {
            $key = "source:{$source->id}";
            $quiet = $source->last_item_at === null || $source->last_item_at->lt(now()->subHours(self::SOURCE_MAX_HOURS));

            if ($quiet) {
                $alerts += (int) $this->alerter->raise($key, "Nguồn quan trọng \"{$source->name}\" không có item mới quá ".self::SOURCE_MAX_HOURS.' giờ.');
            } else {
                $recoveries += (int) $this->alerter->resolve($key, "Nguồn \"{$source->name}\" đã có item mới trở lại.");
            }
        }

        return ['alerts' => $alerts, 'recoveries' => $recoveries];
    }
}
