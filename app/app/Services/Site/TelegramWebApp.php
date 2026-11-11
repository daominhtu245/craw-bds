<?php

namespace App\Services\Site;

use App\Services\Notify\TelegramConfig;

/**
 * Validates Telegram Mini App initData (core.telegram.org/bots/webapps,
 * "Validating data received via the Mini App"):
 *   secret = HMAC_SHA256(key "WebAppData", bot token)
 *   hash   = hex(HMAC_SHA256(key secret, data_check_string))
 * where data_check_string is every field except "hash", sorted by key, "key=value" joined by "\n".
 *
 * Only the username is used (and only when the customer picks "contact me on Telegram").
 * The Telegram user id is never stored.
 */
class TelegramWebApp
{
    public function __construct(private readonly TelegramConfig $config) {}

    /** @return array<string, mixed>|null the Telegram user when initData is valid and fresh */
    public function validate(?string $initData, ?int $now = null): ?array
    {
        $token = $this->config->botToken();
        if ($initData === null || trim($initData) === '' || $token === '') {
            return null;
        }

        parse_str($initData, $fields);
        $hash = (string) ($fields['hash'] ?? '');
        unset($fields['hash']);

        if ($hash === '' || ! isset($fields['auth_date'])) {
            return null;
        }

        ksort($fields);
        $dataCheck = collect($fields)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);

        if (! hash_equals(hash_hmac('sha256', $dataCheck, $secret), $hash)) {
            return null;
        }

        $age = ($now ?? time()) - (int) $fields['auth_date'];
        if ($age < 0 || $age > (int) config('leadradar.site.webapp_max_age_seconds')) {
            return null;
        }

        $user = json_decode((string) ($fields['user'] ?? '{}'), true);

        return is_array($user) ? $user : [];
    }
}
