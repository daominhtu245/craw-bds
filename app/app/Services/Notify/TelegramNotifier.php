<?php

namespace App\Services\Notify;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends messages to the team chat through the Telegram Bot API (SPEC 13).
 * Read-only toward users: it only posts into the team's own chat.
 */
class TelegramNotifier
{
    private const FLAGS = ['en' => '🇬🇧', 'ru' => '🇷🇺', 'ko' => '🇰🇷', 'zh' => '🇨🇳', 'vi' => '🇻🇳'];

    private const STAGES = [
        'pre_arrival' => 'chưa sang VN',
        'in_city_searching' => 'đang ở Đà Nẵng',
        'urgent' => 'cần gấp',
        'unknown' => 'chưa rõ',
    ];

    private const SOURCE_TYPES = ['telegram' => 'Telegram', 'facebook' => 'Facebook', 'forum' => 'Forum', 'reddit' => 'Reddit', 'threads' => 'Threads'];

    public function sendLead(Lead $lead): void
    {
        [$text, $buttons] = $this->leadMessage($lead);
        $this->send($text, $buttons);
    }

    /**
     * @return array{0: string, 1: list<array{text: string, url: string}>}
     */
    public function leadMessage(Lead $lead): array
    {
        $lead->loadMissing(['rawItem.source', 'matchedListings']);
        $item = $lead->rawItem;
        $source = $item->source;
        $language = strtolower($lead->language);

        $header = $lead->intent === LeadIntent::LeavingHandover
            ? '♻️ Nguồn hàng'
            : "🔥 Lead {$lead->score} điểm";

        $lines = [
            sprintf('%s · %s %s · %s: %s',
                $header,
                self::FLAGS[$language] ?? '🌐',
                strtoupper($language),
                self::SOURCE_TYPES[$source->type->value] ?? $source->type->value,
                $this->e($source->name),
            ),
            $this->e($lead->summary_vi),
            sprintf('⏱ Đăng %s · Giai đoạn: %s',
                $item->posted_at->copy()->locale('vi')->diffForHumans(),
                self::STAGES[$lead->stage instanceof LeadStage ? $lead->stage->value : 'unknown'],
            ),
        ];

        $best = $lead->matchedListings->first();
        if ($best !== null) {
            $lines[] = sprintf('🏠 Khớp %d căn (tốt nhất: %s – %d$)',
                $lead->matchedListings->count(), $this->e($best->title), $best->price_usd);
        }

        $buttons = [];
        $dashboardUrl = str_replace('{id}', (string) $lead->id, (string) config('leadradar.notify.dashboard_lead_url'));

        foreach (['Mở bài gốc' => $item->url, 'Mở trên dashboard' => $dashboardUrl] as $label => $url) {
            if ($this->isPublicUrl($url)) {
                $buttons[] = ['text' => $label, 'url' => $url];
            } else {
                // Telegram rejects inline buttons pointing at localhost / private hosts.
                $lines[] = sprintf('<a href="%s">%s</a>', $this->e($url), $label);
            }
        }

        return [implode("\n", $lines), $buttons];
    }

    /**
     * @param  list<array{text: string, url: string}>  $buttons
     */
    public function isConfigured(): bool
    {
        return (string) config('leadradar.notify.bot_token') !== '' && (string) config('leadradar.notify.team_chat_id') !== '';
    }

    public function send(string $html, array $buttons = []): void
    {
        $token = (string) config('leadradar.notify.bot_token');
        $chatId = (string) config('leadradar.notify.team_chat_id');

        if ($token === '' || $chatId === '') {
            throw new RuntimeException('TG_BOT_TOKEN / TG_TEAM_CHAT_ID are not configured.');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];

        if ($buttons !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => [$buttons]];
        }

        $url = rtrim((string) config('leadradar.notify.api_base'), '/')."/bot{$token}/sendMessage";
        $response = Http::asJson()->timeout(15)->post($url, $payload);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram sendMessage failed: '.$response->status().' '.$response->json('description', ''));
        }
    }

    /** Escape for Telegram HTML parse mode. */
    public function e(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isPublicUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '' || ! preg_match('~^https?://~i', $url)) {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.invalid') || ! str_contains($host, '.')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}
