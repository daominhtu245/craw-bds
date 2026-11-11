<?php

namespace App\Services\Leads;

use App\Enums\FilterReason;
use App\Enums\RawItemStatus;
use App\Jobs\PrefilterItemJob;
use App\Models\KnownAgent;
use App\Models\RawItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Takes an author off the known-agent list (marked by mistake). Optionally
 * sends their posts that were dropped for that reason back through the prefilter.
 */
class KnownAgentRemover
{
    /** @return int number of posts sent back to the prefilter */
    public function remove(KnownAgent $agent, bool $requeue): int
    {
        $ids = $requeue
            ? RawItem::query()
                ->where('author_key', $agent->author_key)
                ->where('status', RawItemStatus::FilteredOut->value)
                ->where('filter_reason', FilterReason::KnownAgent->value)
                ->pluck('id')
            : collect();

        DB::transaction(function () use ($agent, $ids) {
            $agent->delete();

            if ($ids->isNotEmpty()) {
                RawItem::query()->whereKey($ids)->update(['status' => RawItemStatus::New->value, 'filter_reason' => null]);
            }
        });

        foreach ($ids as $id) {
            PrefilterItemJob::dispatch($id)->afterCommit();
        }

        Log::info('known_agent.removed', ['author_key' => substr($agent->author_key, 0, 12), 'requeued' => $ids->count()]);

        return $ids->count();
    }
}
