<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Values edited on the Settings page that override config/leadradar.php (and so .env).
 *
 * Everything in the app reads config(), so applying the overrides at boot and
 * before every queued job is enough; a worker picks up a change on its next job.
 * An empty field means "use .env". Secrets are stored encrypted with APP_KEY.
 */
class ConfigOverrides
{
    public const SETTING_KEY = 'config_overrides';

    private const CACHE_KEY = 'leadradar:config_overrides';

    /** config key (under "leadradar.") => type */
    public const FIELDS = [
        // AI
        'ai.api_key' => 'secret',
        'ai.model_classify' => 'string',
        'ai.model_escalate' => 'string',
        'ai.model_draft' => 'string',
        'ai.daily_budget_usd' => 'float',
        'ai.min_confidence' => 'float',
        // Prefilter
        'prefilter.accept_vi' => 'bool',
        'prefilter.max_age_days' => 'int',
        'prefilter.min_length' => 'int',
        'prefilter.duplicate_window_days' => 'int',
        'prefilter.negative_score_threshold' => 'int',
        'prefilter.strong_positive_weight' => 'int',
        // Leads
        'leads.expires_after_days' => 'int',
        'leads.known_agent_threshold' => 'int',
        // Public site (App #2)
        'site.enabled' => 'bool',
        'site.default_locale' => 'string',
        'site.per_page' => 'int',
        'site.inquiry_score' => 'int',
        'site.contact_retention_days' => 'int',
        'site.inquiry_rate_limit_per_hour' => 'int',
        'site.min_fill_seconds' => 'int',
    ];

    /** @var array<string, mixed> values from config files / .env, captured at boot */
    private static array $defaults = [];

    /** @var array<string, true> keys this class has overridden in the current app */
    private static array $applied = [];

    /** Called once per app boot: remember the .env values, then apply. */
    public static function boot(): void
    {
        self::$applied = [];
        self::$defaults = [];
        foreach (array_keys(self::FIELDS) as $key) {
            self::$defaults[$key] = config('leadradar.'.$key);
        }

        self::apply();
    }

    /**
     * Copy saved overrides into config(). Only touches keys saved on the Settings
     * page (or just removed from it), so values set at runtime stay as they are.
     * Safe before migrations (no settings table yet).
     */
    public static function apply(): void
    {
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => (array) Setting::get(self::SETTING_KEY, []));
        } catch (Throwable) {
            return;
        }

        foreach (self::FIELDS as $key => $type) {
            $value = array_key_exists($key, $stored) ? self::decode($stored[$key], $type) : null;

            if ($value !== null) {
                config(['leadradar.'.$key => $value]);
                self::$applied[$key] = true;
            } elseif (isset(self::$applied[$key])) {
                // Removed on the Settings page: back to the .env value.
                config(['leadradar.'.$key => self::$defaults[$key] ?? null]);
                unset(self::$applied[$key]);
            }
        }
    }

    /** Value from config files / .env (what an empty field falls back to). */
    public static function default(string $key): mixed
    {
        return array_key_exists($key, self::$defaults) ? self::$defaults[$key] : config('leadradar.'.$key);
    }

    /** @return array<string, mixed> saved values for the form (secrets never returned) */
    public static function formValues(): array
    {
        $stored = (array) Setting::get(self::SETTING_KEY, []);
        $values = [];

        foreach (self::FIELDS as $key => $type) {
            $values[$key] = $type === 'secret' ? null : (array_key_exists($key, $stored) ? self::decode($stored[$key], $type) : null);
        }

        return $values;
    }

    public static function hasStored(string $key): bool
    {
        return array_key_exists($key, (array) Setting::get(self::SETTING_KEY, []));
    }

    /**
     * @param  array<string, mixed>  $values  key => value; null / '' clears the override,
     *                                        except secrets where empty keeps the saved one
     * @param  list<string>  $clearSecrets  secrets to remove (fall back to .env)
     */
    public static function save(array $values, array $clearSecrets = []): void
    {
        $stored = (array) Setting::get(self::SETTING_KEY, []);

        foreach (self::FIELDS as $key => $type) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $value = $values[$key];

            if ($type === 'secret') {
                if (in_array($key, $clearSecrets, true)) {
                    unset($stored[$key]);
                } elseif (is_string($value) && trim($value) !== '') {
                    $stored[$key] = Crypt::encryptString(trim($value));
                }

                continue;
            }

            if ($value === null || $value === '') {
                unset($stored[$key]);
            } else {
                $stored[$key] = self::cast($value, $type);
            }
        }

        foreach ($clearSecrets as $key) {
            unset($stored[$key]);
        }

        Setting::put(self::SETTING_KEY, $stored);
        Cache::forget(self::CACHE_KEY);
        self::apply();
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function decode(mixed $value, string $type): mixed
    {
        if ($type !== 'secret') {
            return self::cast($value, $type);
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            return null;   // APP_KEY changed: fall back to .env
        }
    }

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => trim((string) $value),
        };
    }
}
