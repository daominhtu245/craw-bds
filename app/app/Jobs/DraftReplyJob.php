<?php

namespace App\Jobs;

use App\Jobs\Concerns\RespectsAiBudget;
use App\Models\Lead;
use App\Services\Ai\ClaudeException;
use App\Services\Ai\ReplyDrafter;
use App\Services\Classify\ClassifierMode;
use App\Services\Drafts\TemplateDrafter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Generates reply drafts for a lead (SPEC 12): AI or templates, see
 * ClassifierMode::draftEngine(). `force` is used by the dashboard "Tạo lại"
 * button; otherwise existing drafts are kept.
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

    public function handle(ReplyDrafter $drafter, ?TemplateDrafter $templates = null, ?ClassifierMode $mode = null): void
    {
        $lead = Lead::query()->find($this->leadId);

        if ($lead === null || (! $this->force && $lead->replyDrafts()->exists())) {
            return;
        }

        $templates ??= app(TemplateDrafter::class);
        $mode ??= app(ClassifierMode::class);
        $engine = $mode->draftEngine();

        if ($engine === 'ai') {
            if ($this->pauseIfOverBudget()) {
                return;
            }

            try {
                $drafter->draft($lead);
                Log::info('lead.drafted', ['job' => 'DraftReplyJob', 'lead_id' => $lead->id, 'engine' => 'ai']);

                return;
            } catch (ClaudeException $e) {
                if ($mode->mode() !== ClassifierMode::AUTO) {
                    throw $e;
                }
                Log::warning('lead.draft_ai_failed_fallback_template', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
            }
        }

        $templates->draft($lead);
        Log::info('lead.drafted', ['job' => 'DraftReplyJob', 'lead_id' => $lead->id, 'engine' => 'template']);
    }
}
