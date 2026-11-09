<?php

namespace App\Services\Leads;

use App\Enums\LeadStatus;
use App\Enums\RawItemStatus;
use App\Models\Lead;
use App\Models\RawItem;
use App\Services\Ai\UsageCost;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for dashboard widgets (SPEC 14.4). All queries run on UTC columns.
 */
class LeadStats
{
    public function __construct(private readonly UsageCost $cost) {}

    /** Midnight in the display timezone, expressed in UTC. */
    public function startOfTodayUtc(): Carbon
    {
        return now(config('leadradar.display_timezone'))->startOfDay()->utc();
    }

    public function leadsSince(CarbonInterface $since): int
    {
        return Lead::query()->where('created_at', '>=', $since)->count();
    }

    /** Share of leads marked invalid among leads created in the window, null if none. */
    public function invalidRate(int $days): ?float
    {
        $base = Lead::query()->where('created_at', '>=', now()->subDays($days));
        $total = (clone $base)->count();

        return $total === 0 ? null : (clone $base)->where('status', LeadStatus::Invalid->value)->count() / $total;
    }

    public function aiCostSince(CarbonInterface $since): float
    {
        return RawItem::query()
            ->where('updated_at', '>=', $since)
            ->whereNotNull('ai_model')
            ->select('ai_model', DB::raw('SUM(ai_input_tokens) as input_tokens'), DB::raw('SUM(ai_output_tokens) as output_tokens'))
            ->groupBy('ai_model')
            ->get()
            ->sum(fn ($row) => $this->cost->usd($row->ai_model, (int) $row->input_tokens, (int) $row->output_tokens));
    }

    public function aiCallsSince(CarbonInterface $since): int
    {
        return RawItem::query()->where('updated_at', '>=', $since)->whereNotNull('ai_model')->count();
    }

    /** Share of processed items filtered before the AI, null when nothing was processed. */
    public function prefilterRate(CarbonInterface $since): ?float
    {
        $processed = RawItem::query()->where('created_at', '>=', $since)->where('status', '!=', RawItemStatus::New->value);
        $total = (clone $processed)->count();

        return $total === 0 ? null : (clone $processed)->where('status', RawItemStatus::FilteredOut->value)->count() / $total;
    }

    /** @return array{collected: int, passed: int, leads: int, contacted: int, won: int} */
    public function funnel(CarbonInterface $since): array
    {
        $items = RawItem::query()->where('created_at', '>=', $since);
        $leads = Lead::query()->where('created_at', '>=', $since);
        $contactedStatuses = [LeadStatus::Contacted, LeadStatus::Viewing, LeadStatus::Negotiating, LeadStatus::Won, LeadStatus::Lost];

        return [
            'collected' => (clone $items)->count(),
            'passed' => (clone $items)->whereIn('status', [RawItemStatus::Queued->value, RawItemStatus::Classified->value, RawItemStatus::Error->value])->count(),
            'leads' => (clone $leads)->count(),
            'contacted' => (clone $leads)->whereIn('status', array_map(fn ($s) => $s->value, $contactedStatuses))->count(),
            'won' => (clone $leads)->where('status', LeadStatus::Won->value)->count(),
        ];
    }

    /** @return array<string, int> */
    public function leadsBySourceType(CarbonInterface $since): array
    {
        return Lead::query()
            ->join('raw_items', 'raw_items.id', '=', 'leads.raw_item_id')
            ->join('sources', 'sources.id', '=', 'raw_items.source_id')
            ->where('leads.created_at', '>=', $since)
            ->selectRaw('sources.type as source_type, COUNT(*) as total')
            ->groupBy('sources.type')
            ->orderBy('sources.type')
            ->pluck('total', 'source_type')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @return array<string, int> */
    public function leadsByLanguage(CarbonInterface $since): array
    {
        return Lead::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('language, COUNT(*) as total')
            ->groupBy('language')
            ->orderBy('language')
            ->pluck('total', 'language')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** @return array<string, int> filter_reason (or "passed") => count */
    public function filterReasons(CarbonInterface $since): array
    {
        return RawItem::query()
            ->where('created_at', '>=', $since)
            ->where('status', '!=', RawItemStatus::New->value)
            ->selectRaw("COALESCE(filter_reason, 'passed') as reason, COUNT(*) as total")
            ->groupBy('reason')
            ->orderByDesc('total')
            ->pluck('total', 'reason')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
