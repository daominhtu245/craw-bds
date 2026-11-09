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
    public const CACHE_KEY = 'leadradar:keywords:v2';

    /**
     * Prefilter keywords.
     *
     * @return array{positive: list<array{term: string, weight: int, is_regex: bool}>, negative: list<array{term: string, weight: int, is_regex: bool}>}
     */
    public function all(): array
    {
        $grouped = $this->grouped();

        return ['positive' => $grouped['positive'] ?? [], 'negative' => $grouped['negative'] ?? []];
    }

    /** @return list<array{term: string, weight: int, is_regex: bool}> */
    public function ofKind(KeywordKind $kind): array
    {
        return $this->grouped()[$kind->value] ?? [];
    }

    /** @return array<string, list<array{term: string, weight: int, is_regex: bool}>> */
    private function grouped(): array
    {
        return Cache::remember(self::CACHE_KEY, (int) config('leadradar.prefilter.cache_ttl_seconds'), function () {
            $grouped = [];

            Keyword::query()
                ->where('is_active', true)
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
