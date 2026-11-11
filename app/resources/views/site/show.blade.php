@extends('site.layout')

@php
    $locale = app()->getLocale();
    $title = $listing->text('title', $locale);
    $photos = $listing->photoUrls();
    $description = $listing->text('description', $locale);
    $policy = $listing->text('policy', $locale);
    $projectText = $listing->project?->localizedDescription($locale);
@endphp

@section('title', $title.' · '.$agency)
@section('description', \Illuminate\Support\Str::limit((string) $description, 150))

@section('content')
    <a href="{{ route('site.index') }}" class="site-muted mb-4 inline-block text-sm" data-hide-in-telegram>{{ __('site.back') }}</a>

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="lg:col-span-3">
            @if ($photos === [])
                <div class="site-muted flex aspect-[4/3] items-center justify-center rounded-2xl bg-slate-100">{{ __('site.no_photo') }}</div>
            @else
                <img src="{{ $photos[0] }}" alt="{{ $title }}" class="aspect-[4/3] w-full rounded-2xl object-cover">
                @if (count($photos) > 1)
                    <div class="mt-3 flex snap-x gap-2 overflow-x-auto pb-2" aria-label="{{ __('site.photos') }}">
                        @foreach (array_slice($photos, 1) as $photo)
                            <a href="{{ $photo }}" target="_blank" rel="noopener" class="shrink-0 snap-start">
                                <img src="{{ $photo }}" alt="{{ $title }}" loading="lazy" class="h-24 w-32 rounded-lg object-cover">
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        <div class="space-y-4 lg:col-span-2">
            @if ($listing->project)
                <p class="site-accent-text text-sm font-semibold uppercase tracking-wide">{{ $listing->project->name }}</p>
            @endif
            <h1 class="text-2xl font-bold">{{ $title }}</h1>
            <p class="text-2xl font-semibold">{{ \App\Services\Site\ListingCatalog::price($listing->price_usd, $locale) }}</p>

            <ul class="site-card space-y-1 rounded-2xl border border-slate-200 p-4 text-sm">
                <li>📍 {{ \App\Services\Site\ListingCatalog::areaName($listing->area, $locale) }}</li>
                <li>🛏 {{ trans_choice('site.bedrooms', $listing->bedrooms, ['count' => $listing->bedrooms]) }}
                    @if ($listing->size_m2) · {{ __('site.size', ['size' => $listing->size_m2]) }} @endif</li>
                <li>📅 {{ __('site.min_term', ['count' => $listing->min_term_months]) }}</li>
                <li>🔑 {{ $listing->available_from && $listing->available_from->isFuture()
                    ? __('site.available_from', ['date' => $listing->available_from->format('d/m/Y')])
                    : __('site.available_now') }}</li>
                @if ($listing->furnished) <li>🛋 {{ __('site.furnished') }}</li> @endif
                @if ($listing->pet_friendly) <li>🐾 {{ __('site.pet_friendly') }}</li> @endif
            </ul>

            @if (! empty($listing->features))
                <div>
                    <h2 class="mb-2 font-semibold">{{ __('site.features') }}</h2>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($listing->features as $feature)
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs">{{ __('site.feature.'.$feature) }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <a href="#inquiry" class="site-accent block rounded-lg px-4 py-3 text-center font-semibold">{{ __('site.form_submit') }}</a>
        </div>
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-5">
        <div class="space-y-6 lg:col-span-3">
            @if ($description)
                <section>
                    <h2 class="mb-2 text-lg font-semibold">{{ __('site.description') }}</h2>
                    <div class="whitespace-pre-line leading-relaxed">{{ $description }}</div>
                </section>
            @endif
            @if ($policy)
                <section>
                    <h2 class="mb-2 text-lg font-semibold">{{ __('site.policy') }}</h2>
                    <div class="whitespace-pre-line leading-relaxed">{{ $policy }}</div>
                </section>
            @endif
            @if ($projectText)
                <section>
                    <h2 class="mb-2 text-lg font-semibold">{{ __('site.about_project') }}: {{ $listing->project->name }}</h2>
                    <div class="whitespace-pre-line leading-relaxed">{{ $projectText }}</div>
                </section>
            @endif
        </div>
        <div class="lg:col-span-2">
            @include('site.partials.inquiry-form', ['listing' => $listing])
        </div>
    </div>
@endsection
