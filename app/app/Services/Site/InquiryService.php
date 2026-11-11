<?php

namespace App\Services\Site;

use App\Enums\ActivityType;
use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\PropertyType;
use App\Enums\RawItemStatus;
use App\Jobs\NotifyInquiryJob;
use App\Models\Inquiry;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\RawItem;
use App\Models\Source;
use App\Services\Currency\CurrencyConverter;
use App\Services\Ingest\IngestService;
use App\Services\Matching\ListingMatcher;
use App\Services\Privacy\PiiMasker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a request from the public site / Telegram Mini App into an inquiry and a lead.
 *
 * - A tracking code (?ref=) from a reply draft attaches the inquiry to that lead.
 * - Otherwise a lead is created with source type "website" and a high score
 *   (the customer reached out to us).
 * - Sales are told on Telegram right away (NotifyInquiryJob), contact included.
 */
class InquiryService
{
    public function __construct(
        private readonly PiiMasker $masker,
        private readonly ListingMatcher $matcher,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated form data
     * @param  array<string, mixed>|null  $telegramUser  validated Mini App user
     */
    public function submit(array $data, string $locale, ?array $telegramUser, string $ip): Inquiry
    {
        $listing = isset($data['listing_id'])
            ? Listing::query()->published()->find($data['listing_id'])
            : null;

        [$channel, $contact] = $this->contact($data, $telegramUser);
        $source = $telegramUser !== null ? 'telegram_miniapp' : 'website';
        $existingLeadId = LeadRef::decode($data['ref'] ?? null);
        $existingLead = $existingLeadId !== null ? Lead::query()->find($existingLeadId) : null;

        $inquiry = DB::transaction(function () use ($data, $locale, $listing, $channel, $contact, $source, $existingLead, $ip) {
            $inquiry = Inquiry::query()->create([
                'listing_id' => $listing?->id,
                'source' => $source,
                'locale' => $locale,
                'name' => trim((string) $data['name']),
                'contact_channel' => $channel,
                'contact' => $contact,
                'arrival_date' => $data['arrival_date'] ?? null,
                'duration_months' => $data['duration_months'] ?? null,
                'budget_usd' => $this->budgetUsd($data['budget'] ?? null, $locale),
                'message' => $this->masker->mask(trim((string) ($data['message'] ?? ''))) ?: null,
                'ref' => $existingLead !== null ? ($data['ref'] ?? null) : null,
                'ip_hash' => hash('sha256', $ip.'|'.config('app.key')),
                'consent_at' => now(),
            ]);

            $lead = $existingLead ?? $this->createLead($inquiry, $listing);
            $inquiry->update(['lead_id' => $lead->id]);

            $lead->activities()->create([
                'type' => ActivityType::System,
                'body' => sprintf('Khách gửi yêu cầu qua %s%s.',
                    $source === 'telegram_miniapp' ? 'Telegram Mini App' : 'website',
                    $listing ? ' cho căn "'.$listing->title.'"' : ''),
                'meta' => ['inquiry_id' => $inquiry->id],
            ]);

            return $inquiry;
        });

        NotifyInquiryJob::dispatch($inquiry->id)->afterCommit();
        Log::info('inquiry.created', ['inquiry_id' => $inquiry->id, 'lead_id' => $inquiry->lead_id, 'source' => $source, 'attached' => $existingLead !== null]);

        return $inquiry;
    }

    /** @return array{0: string, 1: string} */
    private function contact(array $data, ?array $telegramUser): array
    {
        $channel = (string) $data['contact_channel'];

        // "Contact me on Telegram" inside the Mini App: use the username Telegram vouches for.
        if ($channel === 'telegram_self') {
            $username = (string) ($telegramUser['username'] ?? '');

            return ['telegram', $username !== '' ? '@'.$username : trim((string) ($data['contact'] ?? ''))];
        }

        return [$channel, trim((string) $data['contact'])];
    }

    /** Vietnamese form asks in million VND, the others in USD. */
    private function budgetUsd(mixed $budget, string $locale): ?int
    {
        if ($budget === null || $budget === '' || (float) $budget <= 0) {
            return null;
        }

        if ($locale !== 'vi') {
            return (int) round((float) $budget);
        }

        $usd = CurrencyConverter::fromSettings()->toUsd((float) $budget * 1_000_000, 'VND');

        return $usd !== null ? (int) round($usd) : null;
    }

    private function createLead(Inquiry $inquiry, ?Listing $listing): Lead
    {
        $source = Source::query()->firstOrCreate(
            ['type' => 'website', 'identifier' => $inquiry->source],
            ['name' => $inquiry->source === 'telegram_miniapp' ? 'Telegram Mini App' : 'Website', 'is_active' => true],
        );

        $summary = $this->summary($inquiry, $listing);
        $url = $listing
            ? route('site.listing', ['locale' => $inquiry->locale, 'listing' => $listing->slug])
            : route('site.index', ['locale' => $inquiry->locale]);

        $item = RawItem::query()->create([
            'source_id' => $source->id,
            'external_id' => 'inquiry:'.$inquiry->id,
            'url' => $url,
            // Same formula as collectors; the raw contact itself is never stored here.
            'author_key' => IngestService::authorKey('website', mb_strtolower((string) $inquiry->contact)),
            'text' => $summary.($inquiry->message ? "\n".$inquiry->message : ''),
            'content_hash' => hash('sha256', 'inquiry:'.$inquiry->id),
            'language' => $inquiry->locale,
            'posted_at' => now(),
            'status' => RawItemStatus::Classified,
            'ai_model' => 'inquiry-form',
        ]);

        $lead = Lead::query()->create([
            'raw_item_id' => $item->id,
            'intent' => LeadIntent::TenantDemand,
            'stage' => $this->stage($inquiry->arrival_date),
            'language' => $inquiry->locale,
            'summary_vi' => $summary,
            'arrival_date' => $inquiry->arrival_date,
            'duration_months' => $inquiry->duration_months,
            'property_type' => $listing?->property_type ?? PropertyType::Unknown,
            'bedrooms_min' => $listing?->bedrooms,
            'areas' => $listing ? [$listing->area_slug] : [],
            'budget_max_usd' => $inquiry->budget_usd,
            'requirements' => [],
            'confidence' => 1.0,
            'score' => (int) config('leadradar.site.inquiry_score'),
            'status' => LeadStatus::New,
            'expires_at' => now()->addDays((int) config('leadradar.leads.expires_after_days')),
        ]);

        $this->matcher->sync($lead, $this->matcher->match($lead));

        return $lead;
    }

    private function stage(?\DateTimeInterface $arrival): LeadStage
    {
        if ($arrival === null) {
            return LeadStage::Unknown;
        }

        return CarbonImmutable::parse($arrival)->lte(now()->addDays(7)) ? LeadStage::Urgent : LeadStage::PreArrival;
    }

    private function summary(Inquiry $inquiry, ?Listing $listing): string
    {
        $parts = [];
        if ($listing) {
            $parts[] = 'căn "'.$listing->title.'"';
        }
        if ($inquiry->arrival_date) {
            $parts[] = 'đến '.$inquiry->arrival_date->format('d/m');
        }
        if ($inquiry->duration_months) {
            $parts[] = $inquiry->duration_months.' tháng';
        }
        if ($inquiry->budget_usd) {
            $parts[] = 'ngân sách ≤ '.$inquiry->budget_usd.'$';
        }

        $via = $inquiry->source === 'telegram_miniapp' ? 'Telegram Mini App' : 'website';

        return mb_substr('Khách gửi yêu cầu qua '.$via.($parts ? ': '.implode(', ', $parts) : ''), 0, 200);
    }
}
