<?php

namespace App\Jobs;

use App\Enums\LeadStage;
use App\Models\Lead;
use App\Services\Notify\QuietHours;
use App\Services\Notify\TelegramNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a lead to the team Telegram chat (SPEC 13). A lead that already has
 * notified_at is never sent again; the timestamp is claimed atomically first
 * and released if sending fails, so retries stay safe.
 */
class NotifyLeadJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(public readonly int $leadId)
    {
        $this->onQueue('notify');
    }

    public function uniqueId(): string
    {
        return (string) $this->leadId;
    }

    public function handle(TelegramNotifier $notifier, QuietHours $quietHours): void
    {
        // Local / trial setups without a bot: the lead stays on the dashboard, nothing to retry.
        if (! $notifier->isConfigured()) {
            Log::warning('lead.notify_skipped_not_configured', ['job' => 'NotifyLeadJob', 'lead_id' => $this->leadId]);

            return;
        }

        // Quiet hours: keep the lead for the digest, except urgent leads (SPEC 13).
        if ($quietHours->isQuiet()) {
            $lead = Lead::query()->find($this->leadId);
            if ($lead === null || $lead->stage !== LeadStage::Urgent) {
                return;
            }
        }

        $claimed = Lead::query()->whereKey($this->leadId)->whereNull('notified_at')->update(['notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        try {
            $notifier->sendLead(Lead::query()->findOrFail($this->leadId));
        } catch (Throwable $e) {
            Lead::query()->whereKey($this->leadId)->update(['notified_at' => null]);
            throw $e;
        }

        Log::info('lead.notified', ['job' => 'NotifyLeadJob', 'lead_id' => $this->leadId]);
    }
}
