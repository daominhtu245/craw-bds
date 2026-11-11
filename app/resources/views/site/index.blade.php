@extends('site.layout')

@section('content')
    @php($locale = app()->getLocale())
    <section class="mb-6">
        <h1 class="text-2xl font-bold sm:text-3xl">{{ __('site.hero_title') }}</h1>
        <p class="site-muted mt-2">{{ __('site.hero_text') }}</p>
    </section>

    <form method="GET" action="{{ route('site.index') }}" class="site-card mb-6 grid gap-3 rounded-2xl border border-slate-200 p-4 sm:grid-cols-5">
        <label class="block text-sm">
            <span class="site-muted">{{ __('site.filter_area') }}</span>
            <select name="area" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-2">
                <option value="">{{ __('site.filter_any_area') }}</option>
                @foreach ($areas as $slug => $name)
                    <option value="{{ $slug }}" @selected(($filters['area'] ?? null) === $slug)>{{ $name }}</option>
                @endforeach
            </select>
        </label>
        @if ($projects->isNotEmpty())
            <label class="block text-sm">
                <span class="site-muted">{{ __('site.filter_project') }}</span>
                <select name="project" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-2">
                    <option value="">{{ __('site.filter_any_project') }}</option>
                    @foreach ($projects as $slug => $name)
                        <option value="{{ $slug }}" @selected(($filters['project'] ?? null) === $slug)>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="block text-sm">
            <span class="site-muted">{{ __('site.filter_bedrooms') }}</span>
            <select name="bedrooms" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-2">
                <option value="">{{ __('site.filter_any') }}</option>
                @foreach ([1, 2, 3] as $n)
                    <option value="{{ $n }}" @selected((int) ($filters['bedrooms'] ?? 0) === $n)>{{ __('site.filter_bedrooms_min', ['count' => $n]) }}</option>
                @endforeach
            </select>
        </label>
        <label class="block text-sm">
            <span class="site-muted">{{ __('site.filter_max_price') }}</span>
            <select name="max_price" class="mt-1 w-full rounded-lg border border-slate-300 px-2 py-2">
                <option value="">{{ __('site.filter_any') }}</option>
                @foreach ([300, 500, 700, 1000, 1500] as $usd)
                    <option value="{{ $usd }}" @selected((int) ($filters['max_price'] ?? 0) === $usd)>{{ \App\Services\Site\ListingCatalog::price($usd, $locale) }}</option>
                @endforeach
            </select>
        </label>
        <div class="flex items-end gap-2">
            <button type="submit" class="site-accent flex-1 rounded-lg px-4 py-2 font-medium">{{ __('site.filter_submit') }}</button>
            @if (array_filter($filters))
                <a href="{{ route('site.index') }}" class="site-muted px-2 py-2 text-sm underline">{{ __('site.filter_reset') }}</a>
            @endif
        </div>
    </form>

    <p class="site-muted mb-3 text-sm">{{ trans_choice('site.results', $listings->total(), ['count' => $listings->total()]) }}</p>

    @if ($listings->isEmpty())
        <p class="site-card mb-8 rounded-2xl border border-slate-200 p-6 text-center">{{ __('site.no_results') }}</p>
    @else
        <div class="mb-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($listings as $listing)
                @include('site.partials.card', ['listing' => $listing])
            @endforeach
        </div>
        <div class="mb-8">{{ $listings->links() }}</div>
    @endif

    @include('site.partials.inquiry-form')
@endsection
