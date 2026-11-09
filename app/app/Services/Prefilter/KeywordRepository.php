<?php

namespace App\Services\Prefilter;

use App\Enums\KeywordKind;
use App\Models\Keyword;
use Illuminate\Support\Facades\Cache;

/**
 * Active keywords from the DB, cached (SPEC 8: 10 minutes).
 */
class KeywordRepository
{
    public const CACHE_KEY = 'leadradar:keywords:v1';

    /**
     * @return array{positive: list<array{term: string, weight: int, is_regex: bool}>, negative: list<array{term: string, weight: int, is_regex: bool}>}
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, (int) config('leadradar.prefilter.cache_ttl_seconds'), function () {
            $grouped = ['positive' => [], 'negative' => []];

            Keyword::query()
                ->where('is_active', true)
                ->whereIn('kind', [KeywordKind::Positive->value, KeywordKind::Negative->value])
                ->orderBy('id')
                ->get()
                ->each(function (Keyword $k) use (&$grouped) {
                    $grouped[$k->kind->value][] = [
                        'term' => mb_strtolower($k->term, 'UTF-8'),
                        'weight' => $k->weight,
                        'is_regex' => $k->is_regex,
                    ];
                });

            return $grouped;
        });
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
