<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Default settings (SPEC 20.5). Existing values are kept so re-seeding never
 * overwrites what an admin changed on the dashboard.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // Units of foreign currency per 1 USD (USD = amount / rate). Rough initial values.
            'fx_per_usd' => ['VND' => 26000, 'RUB' => 80, 'EUR' => 0.92, 'KRW' => 1380, 'AUD' => 1.53, 'GBP' => 0.79],
            'notify_min_score' => (int) config('leadradar.notify.min_score'),
            'quiet_hours' => (string) config('leadradar.notify.quiet_hours'),
            'agency_name' => 'YOUR AGENCY',
            'agency_website' => 'https://example.com',
        ];

        foreach ($defaults as $key => $value) {
            Setting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
