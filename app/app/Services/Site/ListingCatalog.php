<?php

namespace App\Services\Site;

use App\Models\Area;
use App\Models\Listing;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Published listings for the public site, with simple filters.
 */
class ListingCatalog
{
    /**
     * @param  array{area?: ?string, bedrooms?: ?int, max_price?: ?int, project?: ?string}  $filters
     */
    public function search(array $filters): LengthAwarePaginator
    {
        return Listing::query()
            ->published()
            ->with('project', 'area')
            ->when($filters['area'] ?? null, fn ($q, $area) => $q->where('area_slug', $area))
            ->when($filters['bedrooms'] ?? null, fn ($q, $bedrooms) => $q->where('bedrooms', '>=', (int) $bedrooms))
            ->when($filters['max_price'] ?? null, fn ($q, $max) => $q->where('price_usd', '<=', (int) $max))
            ->when($filters['project'] ?? null, fn ($q, $slug) => $q->whereHas('project', fn ($p) => $p->where('slug', $slug)))
            ->orderBy('price_usd')
            ->paginate((int) config('leadradar.site.per_page'))
            ->withQueryString();
    }

    /** @return Collection<string, string> slug => display name, only areas that have published listings */
    public function areas(string $locale): Collection
    {
        $slugs = Listing::query()->published()->distinct()->pluck('area_slug');

        return Area::query()->whereIn('slug', $slugs)->orderBy('name_en')
            ->get()
            ->mapWithKeys(fn (Area $a) => [$a->slug => $locale === 'vi' ? $a->name_vi : $a->name_en]);
    }

    /** @return Collection<string, string> */
    public function projects(): Collection
    {
        return Project::query()->where('is_active', true)
            ->whereHas('listings', fn ($q) => $q->published())
            ->orderBy('name')
            ->pluck('name', 'slug');
    }

    public static function areaName(?Area $area, string $locale): string
    {
        if ($area === null) {
            return '';
        }

        return $locale === 'vi' ? $area->name_vi : $area->name_en;
    }

    /** "≈ 13,5 triệu/tháng" for Vietnamese, "$520 / month" otherwise. */
    public static function price(int $usd, string $locale): string
    {
        if ($locale === 'vi') {
            $vndPerUsd = (float) (((array) Setting::get('fx_per_usd', []))['VND'] ?? 0);
            if ($vndPerUsd > 0) {
                $millions = round($usd * $vndPerUsd / 1_000_000, 1);

                return '≈ '.rtrim(rtrim(number_format($millions, 1, ',', '.'), '0'), ',').' '.__('site.million_per_month');
            }
        }

        return '$'.number_format($usd).' '.__('site.per_month');
    }
}
