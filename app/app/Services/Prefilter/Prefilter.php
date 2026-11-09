<?php

namespace App\Services\Prefilter;

use App\Enums\FilterReason;
use App\Models\KnownAgent;
use App\Models\RawItem;
use App\Services\Text\TermMatcher;
use App\Services\Text\TextNormalizer;

/**
 * Rule-based prefilter (SPEC 8). Steps run in order; the first failing step wins.
 *
 *   1 too_old  2 too_short  3 duplicate  4 known_agent
 *   5 lang_vi  6 negative_kw  7 no_positive_kw
 *
 * analyzeText() runs only the text steps (2, 5, 6, 7) and powers the
 * dashboard "quick test" box.
 */
class Prefilter
{
    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly KeywordRepository $keywords,
        private readonly LanguageDetector $languages,
    ) {}

    public function check(RawItem $item): TextAnalysis
    {
        $text = $this->analyzeText($item->text);

        if ($item->posted_at->lt(now()->subDays((int) config('leadradar.prefilter.max_age_days')))) {
            return $text->withReason(FilterReason::TooOld);
        }

        if ($text->reason === FilterReason::TooShort) {
            return $text;
        }

        if ($this->isDuplicate($item)) {
            return $text->withReason(FilterReason::Duplicate);
        }

        if ($item->author_key !== null && KnownAgent::query()->where('author_key', $item->author_key)->exists()) {
            return $text->withReason(FilterReason::KnownAgent);
        }

        return $text;
    }

    public function analyzeText(string $raw): TextAnalysis
    {
        $normalized = $this->normalizer->normalize($raw);
        $language = $this->languages->detect($normalized);

        if (mb_strlen($normalized, 'UTF-8') < (int) config('leadradar.prefilter.min_length')) {
            return new TextAnalysis(FilterReason::TooShort, $language);
        }

        $matchText = $this->normalizer->forMatching($raw);
        $keywords = $this->keywords->all();
        $strongWeight = (int) config('leadradar.prefilter.strong_positive_weight');

        $positive = $this->matches($matchText, $keywords['positive']);
        $negative = $this->matches($matchText, $keywords['negative']);
        $negativeScore = array_sum(array_column($negative, 'weight'));
        $hasStrong = collect($positive)->contains(fn ($k) => $k['weight'] >= $strongWeight);
        $mentionsHousing = $this->mentionsHousing($matchText);

        $reason = match (true) {
            $language === 'vi' => FilterReason::LangVi,
            $negativeScore >= (int) config('leadradar.prefilter.negative_score_threshold') && ! $hasStrong => FilterReason::NegativeKw,
            count($positive) === 0 && ! $mentionsHousing => FilterReason::NoPositiveKw,
            default => null,
        };

        return new TextAnalysis(
            $reason,
            $language,
            array_column($positive, 'term'),
            array_column($negative, 'term'),
            $negativeScore,
            $hasStrong,
            $mentionsHousing,
        );
    }

    private function isDuplicate(RawItem $item): bool
    {
        // Only later copies are duplicates; the earliest item with this hash passes.
        return RawItem::query()
            ->where('content_hash', $item->content_hash)
            ->where('id', '<', $item->id)
            ->where('created_at', '>=', now()->subDays((int) config('leadradar.prefilter.duplicate_window_days')))
            ->exists();
    }

    /**
     * @param  list<array{term: string, weight: int, is_regex: bool}>  $keywords
     * @return list<array{term: string, weight: int, is_regex: bool}>
     */
    private function matches(string $text, array $keywords): array
    {
        return TermMatcher::matches($text, $keywords);
    }

    private function mentionsHousing(string $text): bool
    {
        foreach ((array) config('leadradar.prefilter.housing_terms') as $term) {
            if ($this->containsTerm($text, mb_strtolower($term, 'UTF-8'), false)) {
                return true;
            }
        }

        return false;
    }

    private function containsTerm(string $text, string $term, bool $isRegex): bool
    {
        return TermMatcher::contains($text, $term, $isRegex);
    }
}
