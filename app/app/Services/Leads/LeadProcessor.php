<?php

namespace App\Services\Leads;

use App\Enums\LeadIntent;
use App\Jobs\DraftReplyJob;
use App\Jobs\NotifyLeadJob;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\Setting;
use App\Services\Classify\ClassifierMode;
use App\Services\Matching\ListingMatcher;
use App\Services\Scoring\LeadScorer;
use Illuminate\Support\Facades\Log;

/**
 * Lead pipeline after classification (SPEC 9.4 step 6).
 *
 * Matching runs BEFORE scoring because the score uses the best match_score
 * (SPEC 10, "listing khớp ≥ 60"); SPEC 3 lists them the other way round.
 */
class LeadProcessor
{
    public function __construct(
        private readonly ListingMatcher $matcher,
        private readonly LeadScorer $scorer,
        private readonly ClassifierMode $mode,
    ) {}

    public function process(Lead $lead): Lead
    {
        $lead->loadMissing('rawItem');
        $listings = Listing::query()->where('is_active', true)->get();

        $matches = $this->matcher->match($lead, $listings);
        $this->matcher->sync($lead, $matches);

        $lead->score = $this->scorer->score(
            $lead,
            $lead->rawItem->posted_at,
            now(),
            $listings->min('price_usd'),
            $listings->max('price_usd'),
            $matches->first()['score'] ?? null,
        );
        $lead->save();

        Log::info('lead.scored', ['lead_id' => $lead->id, 'item_id' => $lead->raw_item_id, 'score' => $lead->score]);

        // SPEC 9.4 step 6: drafts for hot leads, then the notification. They run on
        // separate queues so the Telegram alert never waits for the draft.
        // AI drafts need an API key; keyword-only mode skips them (sales write the reply).
        if ($lead->score >= $this->minScore() && $this->mode->draftsEnabled()) {
            DraftReplyJob::dispatch($lead->id);
        }

        if ($this->shouldNotify($lead)) {
            NotifyLeadJob::dispatch($lead->id);
        }

        return $lead;
    }

    private function minScore(): int
    {
        return (int) Setting::get('notify_min_score', config('leadradar.notify.min_score'));
    }

    public function shouldNotify(Lead $lead): bool
    {
        if ($lead->notified_at !== null) {
            return false;
        }

        return $lead->intent === LeadIntent::LeavingHandover || $lead->score >= $this->minScore();
    }
}
