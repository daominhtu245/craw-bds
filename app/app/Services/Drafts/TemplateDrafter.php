<?php

namespace App\Services\Drafts;

use App\Enums\ActivityType;
use App\Models\Area;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\ReplyDraft;
use App\Models\Setting;
use App\Services\Privacy\PiiMasker;
use App\Services\Site\SiteLinks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reply drafts from templates, for when the AI is not used (SPEC 12 drafts
 * without an API key). Same two variants as the AI drafter; the system never
 * sends them, sales copy and post by hand.
 *
 * Templates: settings.reply_templates[language][variant], defaults in
 * config leadradar.drafts.templates. Languages without a template use "en".
 * Placeholders: {agency} {website} {area} {budget} {duration} {arrival}
 * {listing} {listing_price} {listings} {site_link}. A [[ ... ]] block is removed
 * when any placeholder inside it is empty. {site_link} points to the public site
 * (App #2) with a tracking code, so a request sent from there joins this lead.
 */
class TemplateDrafter
{
    public const VARIANTS = ['public_reply', 'dm'];

    public const PLACEHOLDERS = ['agency', 'website', 'area', 'budget', 'duration', 'arrival', 'listing', 'listing_price', 'listings', 'site_link'];

    public function __construct(private readonly PiiMasker $masker) {}

    /** @return Collection<int, ReplyDraft> */
    public function draft(Lead $lead): Collection
    {
        $lead->loadMissing('matchedListings');
        $listings = $lead->matchedListings->take((int) config('leadradar.drafts.max_listings'));
        $language = $this->templateLanguage($lead->language);
        $values = $this->values($lead, $listings, $language);
        $model = (string) config('leadradar.drafts.template_model');

        return DB::transaction(function () use ($lead, $listings, $language, $values, $model) {
            $drafts = collect(self::VARIANTS)->map(fn (string $variant) => $lead->replyDrafts()->create([
                'variant' => $variant,
                'language' => $language,
                'body' => $this->masker->mask($this->render($this->template($language, $variant), $values)),
                'listing_ids' => $listings->pluck('id')->all(),
                'model' => $model,
                'created_by' => 'system',
            ]));

            $lead->activities()->create([
                'type' => ActivityType::System,
                'body' => 'Đã tạo bản nháp trả lời từ template.',
                'meta' => ['language' => $language],
            ]);

            return $drafts;
        });
    }

    /** @param array<string, string> $values */
    public function render(string $template, array $values): string
    {
        $resolve = fn (array $m) => $values[$m[1]] ?? '';

        // Optional blocks first: drop the whole block when a placeholder in it is empty.
        $text = (string) preg_replace_callback('/\[\[(.*?)\]\]/s', function (array $block) use ($values) {
            preg_match_all('/\{(\w+)\}/', $block[1], $names);
            foreach ($names[1] as $name) {
                if (trim($values[$name] ?? '') === '') {
                    return '';
                }
            }

            return $block[1];
        }, $template);

        $text = (string) preg_replace_callback('/\{(\w+)\}/', $resolve, $text);

        // Tidy what removed blocks leave behind.
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/ +([.,!?])/', '$1', $text);
        $text = implode("\n", array_map('trim', explode("\n", $text)));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** @return array<string, array<string, string>> language => variant => template */
    public static function templates(): array
    {
        $defaults = (array) config('leadradar.drafts.templates');
        $saved = (array) Setting::get('reply_templates', []);

        return collect($defaults)->map(fn (array $variants, string $language) => collect($variants)
            ->map(fn (string $default, string $variant) => trim((string) ($saved[$language][$variant] ?? '')) !== '' ? (string) $saved[$language][$variant] : $default)
            ->all())->all();
    }

    private function template(string $language, string $variant): string
    {
        return self::templates()[$language][$variant] ?? '';
    }

    private function templateLanguage(?string $language): string
    {
        return array_key_exists((string) $language, (array) config('leadradar.drafts.templates')) ? (string) $language : 'en';
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return array<string, string>
     */
    private function values(Lead $lead, Collection $listings, string $language): array
    {
        $nameColumn = $language === 'vi' ? 'name_vi' : 'name_en';
        $areaNames = Area::query()->whereIn('slug', (array) $lead->areas)->pluck($nameColumn, 'slug');
        $allAreas = Area::query()->whereIn('slug', $listings->pluck('area_slug'))->pluck($nameColumn, 'slug');
        $perMonth = match ($language) {
            'ru' => '/мес.',
            'vi' => '/tháng',
            default => '/month',
        };
        $best = $listings->first();

        return [
            'agency' => (string) Setting::get('agency_name', ''),
            'website' => (string) Setting::get('agency_website', ''),
            'area' => collect((array) $lead->areas)->map(fn ($slug) => $areaNames[$slug] ?? null)->filter()->implode(' / '),
            'budget' => $lead->budget_max_usd ? $lead->budget_max_usd.'$' : '',
            'duration' => $lead->duration_months ? $lead->duration_months.match ($language) {
                'ru' => ' мес.',
                'vi' => ' tháng',
                default => ' months',
            } : '',
            'arrival' => $lead->arrival_date ? $lead->arrival_date->format('d/m') : '',
            'listing' => $best?->title ?? '',
            'listing_price' => $best ? $best->price_usd.'$' : '',
            'listings' => $listings->map(fn (Listing $l) => sprintf(
                '• %s – %d$%s%s',
                $l->title,
                $l->price_usd,
                $perMonth,
                isset($allAreas[$l->area_slug]) ? ', '.$allAreas[$l->area_slug] : '',
            ))->implode("\n"),
            'site_link' => app(SiteLinks::class)->forLead($lead, $best, $language),
        ];
    }
}
