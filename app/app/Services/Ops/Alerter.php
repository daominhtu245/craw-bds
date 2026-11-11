<?php

namespace App\Services\Ops;

use App\Services\Notify\TelegramNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operational alerts to the team chat (SPEC 13). Each alert key fires once
 * when a problem starts and once when it recovers, never every check.
 */
class Alerter
{
    public function __construct(private readonly TelegramNotifier $telegram) {}

    /** @return bool true when a message was sent */
    public function raise(string $key, string $message): bool
    {
        if (! Cache::add($this->cacheKey($key), now()->toIso8601String(), now()->addDays(30))) {
            return false;
        }

        return $this->send('⚠️ '.$message, $key);
    }

    public function resolve(string $key, string $message): bool
    {
        if (! Cache::pull($this->cacheKey($key))) {
            return false;
        }

        return $this->send('✅ '.$message, $key);
    }

    public function isRaised(string $key): bool
    {
        return Cache::has($this->cacheKey($key));
    }

    private function send(string $text, string $key): bool
    {
        if (! $this->telegram->isConfigured()) {
            Log::warning('alert.send_skipped_not_configured', ['alert' => $key]);

            return false;
        }

        try {
            $this->telegram->send($this->telegram->e($text));

            return true;
        } catch (Throwable $e) {
            Log::error('alert.send_failed', ['alert' => $key, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function cacheKey(string $key): string
    {
        return 'leadradar:alert:'.$key;
    }
}
