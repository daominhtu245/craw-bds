<?php

namespace App\Jobs;

use App\Enums\RawItemStatus;
use App\Models\RawItem;
use App\Services\Prefilter\Prefilter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Rule-based prefilter (SPEC 8). Only items still in status "new" are processed,
 * so re-running the job is a no-op.
 */
class PrefilterItemJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $rawItemId)
    {
        $this->onQueue('prefilter');
    }

    public function uniqueId(): string
    {
        return (string) $this->rawItemId;
    }

    public function handle(Prefilter $prefilter): void
    {
        $item = RawItem::query()->find($this->rawItemId);

        if ($item === null || $item->status !== RawItemStatus::New) {
            return;
        }

        $result = $prefilter->check($item);

        $item->forceFill([
            'language' => $result->language,
            'status' => $result->passed() ? RawItemStatus::Queued : RawItemStatus::FilteredOut,
            'filter_reason' => $result->reason,
        ])->save();

        Log::info('prefilter.done', [
            'job' => 'PrefilterItemJob',
            'item_id' => $item->id,
            'source' => $item->source_id,
            'reason' => $result->reason?->value ?? 'passed',
        ]);

        if ($result->passed()) {
            ClassifyItemJob::dispatch($item->id);
        }
    }
}
