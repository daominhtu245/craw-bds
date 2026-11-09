<?php

namespace App\Services\Notify;

use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * Quiet hours like "23-07" in the display timezone (SPEC 13).
 */
class QuietHours
{
    public function isQuiet(?CarbonInterface $at = null): bool
    {
        $range = $this->range();
        if ($range === null) {
            return false;
        }

        [$start, $end] = $range;
        $hour = ($at ?? now())->copy()->setTimezone(config('leadradar.display_timezone'))->hour;

        return $start <= $end
            ? $hour >= $start && $hour < $end
            : $hour >= $start || $hour < $end;
    }

    /** @return array{0: int, 1: int}|null */
    public function range(): ?array
    {
        $value = (string) Setting::get('quiet_hours', config('leadradar.notify.quiet_hours'));

        if (! preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/', $value, $m)) {
            return null;
        }

        [$start, $end] = [(int) $m[1], (int) $m[2]];

        return $start === $end || $start > 23 || $end > 23 ? null : [$start, $end];
    }
}
