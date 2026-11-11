@php
    $locale = app()->getLocale();
    $photo = $listing->photoUrls()[0] ?? null;
    $url = route('site.listing', ['listing' => $listing->slug]);
@endphp
<article class="site-card overflow-hidden rounded-2xl border border-slate-200 shadow-sm">
    <a href="{{ $url }}" class="block">
        <div class="aspect-[4/3] bg-slate-100">
            @if ($photo)
                <img src="{{ $photo }}" alt="{{ $listing->text('title', $locale) }}" loading="lazy" class="h-full w-full object-cover">
            @else
                <div class="site-muted flex h-full items-center justify-center text-sm">{{ __('site.no_photo') }}</div>
            @endif
        </div>
    </a>
    <div class="space-y-1 p-4">
        @if ($listing->project)
            <p class="site-accent-text text-xs font-semibold uppercase tracking-wide">{{ $listing->project->name }}</p>
        @endif
        <h3 class="font-semibold"><a href="{{ $url }}">{{ $listing->text('title', $locale) }}</a></h3>
        <p class="site-muted text-sm">
            {{ \App\Services\Site\ListingCatalog::areaName($listing->area, $locale) }}
            · {{ trans_choice('site.bedrooms', $listing->bedrooms, ['count' => $listing->bedrooms]) }}
            @if ($listing->size_m2) · {{ __('site.size', ['size' => $listing->size_m2]) }} @endif
        </p>
        <p class="text-lg font-semibold">{{ \App\Services\Site\ListingCatalog::price($listing->price_usd, $locale) }}</p>
    </div>
</article>
