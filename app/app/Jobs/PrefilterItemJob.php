<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Rule-based prefilter (SPEC 8).
 *
 * Phase 1 only wires the pipeline: ingest dispatches this job on the
 * "prefilter" queue. The filtering rules are implemented in Phase 2.
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

    public function handle(): void
    {
        Log::debug('prefilter.pending_phase2', ['item_id' => $this->rawItemId, 'job' => 'PrefilterItemJob']);
    }
}
