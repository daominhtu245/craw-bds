<?php

namespace App\Services\Ai;

use App\Enums\ActivityType;
use App\Models\Lead;
use App\Models\ReplyDraft;
use App\Models\Setting;
use App\Services\Privacy\PiiMasker;
use App\Services\Site\SiteLinks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * AI reply drafts (SPEC 12): two variants, public_reply and dm, saved in
 * reply_drafts. The system never sends them; sales copy and post by hand.
 */
class ReplyDrafter
{
    public const TOOL_NAME = 'record_reply';

    public function __construct(
        private readonly ClaudeClient $client,
        private readonly PromptRepository $prompts,
        private readonly PiiMasker $masker,
        private readonly SiteLinks $siteLinks,
    ) {}

    /** @return Collection<int, ReplyDraft> */
    public function draft(Lead $lead): Collection
    {
        $lead->loadMissing(['rawItem.source', 'matchedListings']);
        $listings = $lead->matchedListings->take(3);

        $siteLink = $this->siteLinks->forLead($lead, $listings->first(), (string) $lead->language);
        $system = $this->prompts->render('reply', [
            'language' => $lead->language,
            'agency_name' => (string) Setting::get('agency_name', 'our agency'),
            'agency_website' => (string) Setting::get('agency_website', ''),
            'site_link' => $siteLink,
        ]);

        $model = (string) config('leadradar.ai.model_draft');
        $result = $this->client->callTool(
            $model,
            $system,
            $this->userMessage($lead, $listings),
            self::tool(),
            (string) config('leadradar.ai.effort_draft'),
            (int) config('leadradar.ai.max_tokens_draft'),
        );

        $listingIds = $listings->pluck('id')->all();

        $bodies = $this->withSiteLink(
            trim((string) ($result->input['public_reply'] ?? '')),
            trim((string) ($result->input['dm'] ?? '')),
            $siteLink,
        );

        return DB::transaction(function () use ($lead, $result, $listingIds, $bodies) {
            $drafts = collect(['public_reply', 'dm'])->map(fn (string $variant) => $lead->replyDrafts()->create([
                'variant' => $variant,
                'language' => $lead->language,
                // Defence in depth: the prompt forbids phone numbers, mask anyway.
                'body' => $this->masker->mask($bodies[$variant]),
                'listing_ids' => $listingIds,
                'model' => $result->model,
                'created_by' => 'system',
            ]));

            $lead->activities()->create([
                'type' => ActivityType::AiDraft,
                'body' => 'AI đã soạn bản nháp trả lời.',
                'meta' => ['model' => $result->model, 'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens],
            ]);

            return $drafts;
        });
    }

    /**
     * The tracking link belongs in the DM only, whatever the model did.
     *
     * @return array{public_reply: string, dm: string}
     */
    private function withSiteLink(string $public, string $dm, string $link): array
    {
        if ($link === '') {
            return ['public_reply' => $public, 'dm' => $dm];
        }

        $public = trim(preg_replace('/\s*'.preg_quote($link, '/').'\s*/u', ' ', $public) ?? $public);
        if (! str_contains($dm, $link)) {
            $dm = rtrim($dm)."\n".$link;
        }

        return ['public_reply' => $public, 'dm' => $dm];
    }

    /** @return array<string, mixed> */
    public static function tool(): array
    {
        return [
            'name' => self::TOOL_NAME,
            'description' => 'Record the two reply drafts.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['public_reply', 'dm'],
                'properties' => [
                    'public_reply' => ['type' => 'string', 'description' => 'Public reply, 60-120 words.'],
                    'dm' => ['type' => 'string', 'description' => 'Private message, 60-120 words.'],
                ],
            ],
        ];
    }

    private function userMessage(Lead $lead, Collection $listings): string
    {
        $item = $lead->rawItem;
        $listingLines = $listings->map(fn ($l) => sprintf(
            '- %s | area: %s | %d USD/month | min %d months | %s%s',
            $l->title,
            $l->area_slug,
            $l->price_usd,
            $l->min_term_months,
            implode(', ', array_merge($l->pet_friendly ? ['pet friendly'] : [], (array) $l->features)),
            $l->url ? ' | '.$l->url : '',
        ))->implode("\n");

        return implode("\n\n", array_filter([
            'language: '.$lead->language,
            'lead summary (Vietnamese, for context): '.$lead->summary_vi,
            'group rules: '.($item->source->rules_note ?: 'none provided'),
            $item->context_text ? "context:\n<context>\n{$item->context_text}\n</context>" : null,
            "original message:\n<message>\n{$item->text}\n</message>",
            "matching listings:\n".($listingLines !== '' ? $listingLines : '(none)'),
        ]));
    }
}
