<?php

namespace App\Jobs;

use App\Enums\RawItemStatus;
use App\Jobs\Concerns\RespectsAiBudget;
use App\Models\RawItem;
use App\Services\Ai\ClaudeException;
use App\Services\Ai\LeadClassifier;
use App\Services\Leads\LeadProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI classification → lead → match → score → notify (SPEC 9).
 * Only items in status "queued" are processed, so re-runs never duplicate leads.
 */
class ClassifyItemJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;
    use RespectsAiBudget;

    public int $tries = 3;

    public int $timeout = 140;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $rawItemId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->rawItemId;
    }

    public function handle(LeadClassifier $classifier, LeadProcessor $processor): void
    {
        $item = RawItem::query()->find($this->rawItemId);

        if ($item === null || $item->status !== RawItemStatus::Queued) {
            return;
        }

        if ($this->pauseIfOverBudget()) {
            return;
        }

        $lead = $classifier->classify($item);

        if ($lead !== null) {
            $processor->process($lead);
        }

        $item->forceFill(['status' => RawItemStatus::Classified])->save();
    }

    public function failed(?Throwable $e): void
    {
        RawItem::query()->whereKey($this->rawItemId)->update(['status' => RawItemStatus::Error->value]);

        Log::error('classify.failed', [
            'job' => 'ClassifyItemJob',
            'item_id' => $this->rawItemId,
            'error' => $e instanceof ClaudeException ? $e->getMessage() : ($e ? $e::class : 'unknown'),
        ]);
    }
}
