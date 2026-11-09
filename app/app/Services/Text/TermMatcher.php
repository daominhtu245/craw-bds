<?php

namespace App\Services\Text;

/**
 * Keyword matching shared by the prefilter and the rule classifier.
 * Text and term are expected lowercased.
 */
final class TermMatcher
{
    /**
     * Plain terms must start at a word boundary but may continue (word prefix),
     * so Russian stems like "квартир" match "квартиру".
     */
    public static function contains(string $text, string $term, bool $isRegex = false): bool
    {
        if ($isRegex) {
            return @preg_match('~'.str_replace('~', '\~', $term).'~iu', $text) === 1;
        }

        return preg_match('~(?<![\p{L}\p{N}])'.preg_quote($term, '~').'~u', $text) === 1;
    }

    /**
     * @param  list<array{term: string, weight: int, is_regex: bool}>  $keywords
     * @return list<array{term: string, weight: int, is_regex: bool}>
     */
    public static function matches(string $text, array $keywords): array
    {
        return array_values(array_filter($keywords, fn (array $k) => self::contains($text, $k['term'], $k['is_regex'])));
    }
}
