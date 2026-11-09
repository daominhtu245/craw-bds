<?php

namespace App\Services\Classify;

use App\Enums\KeywordKind;
use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\PropertyType;
use App\Models\Area;
use App\Services\Ai\Classification;
use App\Services\Ai\ClassificationRun;
use App\Services\Areas\AreaResolver;
use App\Services\Prefilter\KeywordRepository;
use App\Services\Prefilter\LanguageDetector;
use App\Services\Text\TermMatcher;
use App\Services\Text\TextNormalizer;
use Carbon\CarbonInterface;

/**
 * Keyword classifier: the no-API-key alternative to the AI (ClassifierMode).
 *
 * Intent: sum of keyword weights per intent_* kind (editable on the Keywords
 * page); highest wins, ties go to the more specific intent. Stage from
 * stage_* keywords and the arrival date. Fields from FieldExtractor.
 * The output has the same shape as the AI tool input, so everything after
 * classification (lead, matching, scoring, notify) is shared.
 */
class RuleClassifier
{
    /** Tie-break order: the earlier intent wins on equal scores. */
    private const PRIORITY = [
        LeadIntent::LeavingHandover, LeadIntent::Agent, LeadIntent::LandlordOffer,
        LeadIntent::ShortTerm, LeadIntent::OtherCity, LeadIntent::TenantDemand,
    ];

    private const DA_NANG = ['da nang', 'danang', 'đà nẵng', 'đà nẳng', 'дананг', 'да нанг'];

    public function __construct(
        private readonly KeywordRepository $keywords,
        private readonly FieldExtractor $fields,
        private readonly AreaResolver $areas,
        private readonly LanguageDetector $language,
        private readonly TextNormalizer $normalizer,
    ) {}

    public function classifyText(string $text, ?string $context, CarbonInterface $postedAt): ClassificationRun
    {
        $input = $this->analyze($text, $postedAt);

        return new ClassificationRun(
            Classification::fromToolInput($input),
            (string) config('leadradar.classifier.rules_version'),
            0,
            0,
        );
    }

    /** @return array<string, mixed> Same keys as the AI tool input, plus engine/matched for debugging. */
    public function analyze(string $text, CarbonInterface $postedAt): array
    {
        $t = $this->normalizer->forMatching($text);
        $areas = $this->areas->findInText($text);
        [$propertyType, $bedrooms] = $this->fields->propertyType($t);

        [$scores, $matched] = $this->intentScores($t);

        if (isset($scores[LeadIntent::OtherCity->value]) && ($areas !== [] || $this->mentionsDaNang($t))) {
            unset($scores[LeadIntent::OtherCity->value]);
        }
        if ($this->fields->mentionsShortStay($t) && (isset($scores[LeadIntent::TenantDemand->value]) || isset($scores[LeadIntent::ShortTerm->value]))) {
            $scores[LeadIntent::ShortTerm->value] = ($scores[LeadIntent::ShortTerm->value] ?? 0) + 2;
            $matched[] = '(ở ngắn ngày)';
        }
        // A demand phrase alone ("looking for a dentist") is not a housing lead.
        if (isset($scores[LeadIntent::TenantDemand->value]) && $propertyType === PropertyType::Unknown && ! $this->mentionsHousing($t)) {
            unset($scores[LeadIntent::TenantDemand->value]);
        }

        [$intent, $weight] = $this->winner($scores);

        $budget = $this->fields->budget($t);
        $duration = $this->fields->durationMonths($t);
        $arrival = $this->fields->arrivalDate($t, $postedAt);
        $stage = $intent === LeadIntent::TenantDemand ? $this->stage($t, $arrival, $postedAt) : LeadStage::Unknown;
        // A month in a handover or listing is a lease end / availability date, not an arrival.
        if (! in_array($intent, [LeadIntent::TenantDemand, LeadIntent::ShortTerm], true)) {
            $arrival = null;
        }
        $requirements = $this->fields->requirements($t);
        $household = $this->fields->household($t);

        $found = count(array_filter([$budget !== null, $areas !== [], $duration !== null, $propertyType !== PropertyType::Unknown, $arrival !== null]));
        $c = (array) config('leadradar.classifier.confidence');
        $confidence = $intent === LeadIntent::Other
            ? 0.5
            : min((float) $c['max'], (float) $c['base'] + (float) $c['per_weight'] * $weight + (float) $c['per_field'] * $found);

        return [
            'engine' => 'keyword',
            'matched' => array_values(array_unique($matched)),
            'intent' => $intent->value,
            'confidence' => round($confidence, 2),
            'language' => $this->language->detect($text) ?? 'und',
            'stage' => $stage->value,
            'arrival_date' => $arrival,
            'duration_months' => $duration,
            'property_type' => $propertyType->value,
            'bedrooms_min' => $bedrooms,
            'areas_mentioned' => $areas,
            'budget' => $budget,
            'household' => $household,
            'requirements' => $requirements,
            'summary_vi' => $this->summary($intent, $stage, $propertyType, $areas, $duration, $budget, $arrival, $household, $text),
        ];
    }

    /** @return array{0: array<string, int>, 1: list<string>} */
    private function intentScores(string $t): array
    {
        $scores = [];
        $matched = [];

        foreach (KeywordKind::cases() as $kind) {
            $intent = $kind->intent();
            if ($intent === null) {
                continue;
            }

            $hits = TermMatcher::matches($t, $this->keywords->ofKind($kind));
            if ($hits !== []) {
                $scores[$intent->value] = array_sum(array_column($hits, 'weight'));
                array_push($matched, ...array_column($hits, 'term'));
            }
        }

        return [$scores, $matched];
    }

    /**
     * @param  array<string, int>  $scores
     * @return array{0: LeadIntent, 1: int}
     */
    private function winner(array $scores): array
    {
        $best = LeadIntent::Other;
        $bestScore = 0;

        foreach (self::PRIORITY as $intent) {
            $score = $scores[$intent->value] ?? 0;
            if ($score > $bestScore) {
                $best = $intent;
                $bestScore = $score;
            }
        }

        return [$best, $bestScore];
    }

    private function stage(string $t, ?string $arrival, CarbonInterface $postedAt): LeadStage
    {
        $has = fn (KeywordKind $kind) => TermMatcher::matches($t, $this->keywords->ofKind($kind)) !== [];

        return match (true) {
            $has(KeywordKind::StageUrgent) => LeadStage::Urgent,
            $has(KeywordKind::StageInCity) => LeadStage::InCitySearching,
            $has(KeywordKind::StagePreArrival) => LeadStage::PreArrival,
            $arrival !== null && $arrival > $postedAt->copy()->setTimezone(config('leadradar.display_timezone'))->toDateString() => LeadStage::PreArrival,
            default => LeadStage::Unknown,
        };
    }

    private function mentionsHousing(string $t): bool
    {
        foreach ((array) config('leadradar.prefilter.housing_terms') as $term) {
            if (TermMatcher::contains($t, mb_strtolower($term, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    /** "in/to Da Nang" counts; "from Da Nang" / "из Дананга" means leaving it. */
    private function mentionsDaNang(string $t): bool
    {
        foreach (self::DA_NANG as $name) {
            if (preg_match('~(?<![\p{L}])(from|из)\s+'.preg_quote($name, '~').'~u', $t)) {
                continue;
            }
            if (TermMatcher::contains($t, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $areas
     * @param  array{min: ?float, max: ?float, currency: string, period: string, raw: string}|null  $budget
     */
    private function summary(LeadIntent $intent, LeadStage $stage, PropertyType $type, array $areas, ?int $duration, ?array $budget, ?string $arrival, ?string $household, string $text): string
    {
        if (in_array($intent, [LeadIntent::Other, LeadIntent::OtherCity], true)) {
            $prefix = $intent === LeadIntent::OtherCity ? 'Thành phố khác: ' : '';

            return $prefix.TextNormalizer::excerpt($text, 160);
        }

        $what = match ($type) {
            PropertyType::Unknown => 'chỗ ở',
            PropertyType::Studio => 'studio',
            PropertyType::House => 'nhà',
            PropertyType::Villa => 'villa',
            PropertyType::Room => 'phòng',
            default => 'căn '.$type->getLabel(),
        };

        $head = match ($intent) {
            LeadIntent::LeavingHandover => 'Nhượng lại hợp đồng '.$what,
            LeadIntent::LandlordOffer => 'Tin cho thuê '.$what,
            LeadIntent::Agent => 'Môi giới đăng '.$what,
            LeadIntent::ShortTerm => 'Thuê ngắn ngày '.$what,
            default => ($stage === LeadStage::Urgent ? 'Cần gấp ' : 'Tìm ').$what,
        };

        if ($areas !== []) {
            $names = Area::query()->whereIn('slug', $areas)->pluck('name_vi', 'slug');
            $head .= ' ở '.implode(', ', array_map(fn ($s) => $names[$s] ?? $s, $areas));
        }

        $parts = [$head];
        if ($arrival !== null) {
            $parts[] = 'từ '.date('d/m', strtotime($arrival));
        }
        if ($duration !== null) {
            $parts[] = $duration.' tháng';
        }
        if ($budget !== null) {
            $parts[] = 'ngân sách '.$budget['raw'];
        }
        if ($household !== null) {
            $parts[] = strtr($household, ['family with kids' => 'gia đình có con', 'family' => 'gia đình', 'couple' => 'cặp đôi', 'with pets' => 'có thú cưng']);
        }

        return implode(', ', $parts);
    }
}
