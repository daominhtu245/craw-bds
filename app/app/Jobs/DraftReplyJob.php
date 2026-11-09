<?php

namespace App\Jobs;

use App\Jobs\Concerns\RespectsAiBudget;
use App\Models\Lead;
use App\Services\Ai\ReplyDrafter;
use App\Services\Classify\ClassifierMode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Generates reply drafts for a lead (SPEC 12). `force` is used by the
 * dashboard "Tạo lại" button; otherwise existing drafts are kept.
 */
class DraftReplyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RespectsAiBudget;

    public int $tries = 3;

    public int $timeout = 140;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $leadId, public readonly bool $force = false)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->leadId;
    }

    public function handle(ReplyDrafter $drafter): void
    {
        $lead = Lead::query()->find($this->leadId);

        if ($lead === null || (! $this->force && $lead->replyDrafts()->exists())) {
            return;
        }

        if (! app(ClassifierMode::class)->hasApiKey()) {
            Log::info('lead.draft_skipped_no_api_key', ['job' => 'DraftReplyJob', 'lead_id' => $lead->id]);

            return;
        }

        if ($this->pauseIfOverBudget()) {
            return;
        }

        $drafter->draft($lead);

        Log::info('lead.drafted', ['job' => 'DraftReplyJob', 'lead_id' => $lead->id]);
    }
}
