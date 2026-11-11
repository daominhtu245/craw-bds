<?php

namespace App\Services\Notify;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Where lead notifications go (SPEC 13). Values saved on the Settings page win;
 * empty ones fall back to TG_BOT_TOKEN / TG_TEAM_CHAT_ID in .env.
 *
 * The bot token is stored encrypted with APP_KEY and never shown back in the form.
 * The chat can be a group (-100…) or a channel (@name or -100…, bot must be admin).
 */
class TelegramConfig
{
    public const ENABLED = 'telegram_enabled';

    public const BOT_TOKEN = 'telegram_bot_token';

    public const CHAT_ID = 'telegram_chat_id';

    public function enabled(): bool
    {
        return (bool) Setting::get(self::ENABLED, true);
    }

    public function botToken(): string
    {
        $stored = (string) Setting::get(self::BOT_TOKEN, '');

        if ($stored !== '') {
            try {
                return (string) Crypt::decryptString($stored);
            } catch (DecryptException) {
                // APP_KEY changed: the saved token is unreadable, fall back to .env.
            }
        }

        return (string) config('leadradar.notify.bot_token');
    }

    public function chatId(): string
    {
        $stored = trim((string) Setting::get(self::CHAT_ID, ''));

        return $stored !== '' ? $stored : (string) config('leadradar.notify.team_chat_id');
    }

    public function hasStoredToken(): bool
    {
        return (string) Setting::get(self::BOT_TOKEN, '') !== '';
    }

    /** Bot token and chat id are known (enabled or not). */
    public function isComplete(): bool
    {
        return $this->botToken() !== '' && $this->chatId() !== '';
    }

    public function isActive(): bool
    {
        return $this->enabled() && $this->isComplete();
    }

    public function saveToken(string $token): void
    {
        Setting::put(self::BOT_TOKEN, Crypt::encryptString(trim($token)));
    }

    public function clearToken(): void
    {
        Setting::put(self::BOT_TOKEN, '');
    }
}
