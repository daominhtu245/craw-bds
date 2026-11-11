<?php

namespace App\Services\Notify;

use App\Enums\ActivityType;
use App\Enums\LeadIntent;
use App\Models\Lead;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * After quiet hours end, sends ONE summary message for the leads that were
 * held back (SPEC 13). Leads are claimed atomically via notified_at, so a
 * lead is never sent twice even if NotifyLeadJob runs at the same time.
 */
class QuietDigest
{
    public const MAX_LINES = 15;

    public function __construct(
        private readonly QuietHours $quietHours,
        private readonly TelegramNotifier $telegram,
    ) {}

    /** @return int Number of leads included in the digest. */
    public function sendIfDue(): int
    {
        // Turned off or no bot: leave leads unclaimed so they go out once it is on again.
        if ($this->quietHours->isQuiet() || ! $this->telegram->isConfigured()) {
            return 0;
        }

        $minScore = (int) Setting::get('notify_min_score', config('leadradar.notify.min_score'));

        $candidates = Lead::query()
            ->whereNull('notified_at')
            ->where('created_at', '>=', now()->subDay())
            // Give the normal NotifyLeadJob a head start for brand-new leads.
            ->where('created_at', '<=', now()->subMinutes(2))
            ->where(fn ($q) => $q->where('score', '>=', $minScore)->orWhere('intent', LeadIntent::LeavingHandover->value))
            ->orderByDesc('score')
            ->get();

        $claimed = $candidates->filter(
            fn (Lead $lead) => Lead::query()->whereKey($lead->id)->whereNull('notified_at')->update(['notified_at' => now()]) === 1,
        );

        if ($claimed->isEmpty()) {
            return 0;
        }

        try {
            $this->telegram->send($this->message($claimed));
        } catch (\Throwable $e) {
            Lead::query()->whereKey($claimed->modelKeys())->update(['notified_at' => null]);
            throw $e;
        }

        foreach ($claimed as $lead) {
            $lead->activities()->create(['type' => ActivityType::System, 'body' => 'Đã báo trong tin tóm tắt sau giờ yên lặng.']);
        }

        return $claimed->count();
    }

    /** @param Collection<int, Lead> $leads */
    public function message(Collection $leads): string
    {
        $lines = ['🌙 Tóm tắt lead trong giờ yên lặng: '.$leads->count().' lead'];

        foreach ($leads->take(self::MAX_LINES) as $lead) {
            $url = str_replace('{id}', (string) $lead->id, (string) config('leadradar.notify.dashboard_lead_url'));
            $tag = $lead->intent === LeadIntent::LeavingHandover ? '♻️' : "🔥 {$lead->score}";
            $lines[] = sprintf('%s · <a href="%s">%s</a>', $tag, $this->telegram->e($url), $this->telegram->e(mb_strimwidth($lead->summary_vi, 0, 120, '…')));
        }

        if ($leads->count() > self::MAX_LINES) {
            $lines[] = '… và '.($leads->count() - self::MAX_LINES).' lead khác trên dashboard.';
        }

        return implode("\n", $lines);
    }
}
