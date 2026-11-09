<?php

namespace App\Services\Areas;

use App\Models\Area;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Maps free-text area names to area slugs (SPEC 9.4 step 2): case-insensitive,
 * Vietnamese diacritics removed. Unmatched names are logged for new aliases.
 */
class AreaResolver
{
    public const CACHE_KEY = 'leadradar:area-aliases:v1';

    /**
     * @param  list<string>  $mentioned
     * @return list<string> Unique slugs in mention order.
     */
    public function resolve(array $mentioned): array
    {
        $index = $this->index();
        $slugs = [];

        foreach ($mentioned as $name) {
            $key = self::fold($name);
            $slug = $index[$key] ?? $this->containedMatch($key, $index);

            if ($slug === null) {
                Log::info('area.unmatched', ['area' => mb_substr($name, 0, 80)]);

                continue;
            }

            $slugs[$slug] = true;
        }

        return array_keys($slugs);
    }

    /**
     * Exact alias matches only, without logging (dashboard quick test).
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    public function resolveQuietly(array $candidates): array
    {
        $index = $this->index();
        $slugs = [];

        foreach ($candidates as $candidate) {
            $slug = $index[self::fold($candidate)] ?? null;
            if ($slug !== null) {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * Area slugs whose name or alias appears in a free text (keyword classifier).
     *
     * @return list<string> Unique slugs in order of first appearance.
     */
    public function findInText(string $text): array
    {
        $haystack = ' '.self::fold($text).' ';
        $found = [];

        foreach ($this->index() as $alias => $slug) {
            if (mb_strlen($alias) < 3) {
                continue;
            }
            $pos = mb_strpos($haystack, ' '.$alias.' ');
            if ($pos !== false && (! isset($found[$slug]) || $pos < $found[$slug])) {
                $found[$slug] = $pos;
            }
        }

        asort($found);

        return array_keys($found);
    }

    /** Lowercase, strip diacritics (đ → d), collapse spaces and punctuation. */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = str_replace(['đ', 'ё'], ['d', 'е'], $text);

        if (class_exists(\Normalizer::class)) {
            $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_D);
            // Also folds Cyrillic "й" to "и"; aliases and input are folded the same way.
            $text = (string) preg_replace('/\p{Mn}+/u', '', $text);
        }

        $text = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, string> folded alias => slug */
    private function index(): array
    {
        return Cache::remember(self::CACHE_KEY, (int) config('leadradar.prefilter.cache_ttl_seconds'), function () {
            $index = [];

            foreach (Area::query()->orderBy('id')->get() as $area) {
                $names = array_merge([$area->slug, $area->name_en, $area->name_vi, str_replace('-', ' ', $area->slug)], (array) $area->aliases);

                foreach ($names as $name) {
                    $folded = self::fold((string) $name);
                    if ($folded !== '') {
                        $index[$folded] ??= $area->slug;
                    }
                }
            }

            return $index;
        });
    }

    /**
     * "An Thuong area" or "near My Khe beach" → longest alias contained in the text.
     *
     * @param  array<string, string>  $index
     */
    private function containedMatch(string $key, array $index): ?string
    {
        $best = null;
        $bestLength = 0;

        foreach ($index as $alias => $slug) {
            $length = mb_strlen($alias);
            if ($length >= 4 && $length > $bestLength && preg_match('/(^| )'.preg_quote($alias, '/').'( |$)/u', $key)) {
                $best = $slug;
                $bestLength = $length;
            }
        }

        return $best;
    }
}
