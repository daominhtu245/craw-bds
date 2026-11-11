<?php

namespace App\Jobs;

use App\Models\Inquiry;
use App\Models\Lead;
use App\Services\Notify\TelegramNotifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Tells sales about a request from the public site / Mini App, contact included.
 * Sent even during quiet hours: the customer is waiting for an answer.
 */
class NotifyInquiryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(public readonly int $inquiryId)
    {
        $this->onQueue('notify');
    }

    public function uniqueId(): string
    {
        return 'inquiry:'.$this->inquiryId;
    }

    public function handle(TelegramNotifier $notifier): void
    {
        if (! $notifier->isConfigured()) {
            Log::warning('inquiry.notify_skipped_not_configured', ['job' => 'NotifyInquiryJob', 'inquiry_id' => $this->inquiryId]);

            return;
        }

        $inquiry = Inquiry::query()->with(['lead', 'listing'])->find($this->inquiryId);
        if ($inquiry === null) {
            return;
        }

        $notifier->sendInquiry($inquiry);

        // The inquiry message replaces the normal lead alert for this lead.
        if ($inquiry->lead_id !== null) {
            Lead::query()->whereKey($inquiry->lead_id)->whereNull('notified_at')->update(['notified_at' => now()]);
        }

        Log::info('inquiry.notified', ['job' => 'NotifyInquiryJob', 'inquiry_id' => $inquiry->id, 'lead_id' => $inquiry->lead_id]);
    }
}
