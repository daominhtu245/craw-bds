@php
    $locale = app()->getLocale();
    $days = (int) config('leadradar.site.contact_retention_days');
@endphp
<section id="inquiry" class="site-card rounded-2xl border border-slate-200 p-5 shadow-sm">
    <h2 class="text-xl font-semibold">{{ isset($listing) ? __('site.form_title_listing') : __('site.form_title') }}</h2>
    <p class="site-muted mt-1 text-sm">{{ __('site.form_intro') }}</p>

    @if (session('inquiry_sent'))
        <div class="mt-4 rounded-lg bg-emerald-50 p-3 text-sm font-medium text-emerald-800" role="status">{{ __('site.form_sent') }}</div>
    @endif

    @if ($errors->any())
        <div class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700" role="alert">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('site.inquiry') }}#inquiry" class="mt-4 grid gap-4 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="listing_id" value="{{ isset($listing) ? $listing->id : '' }}">
        <input type="hidden" name="ref" value="{{ $ref }}">
        <input type="hidden" name="tg_init_data" value="">
        <input type="hidden" name="started" value="{{ $formStarted }}">
        <div class="hidden" aria-hidden="true">
            <label>Company <input type="text" name="company" tabindex="-1" autocomplete="off"></label>
        </div>

        <label class="block text-sm">
            <span class="font-medium">{{ __('site.form_name') }} *</span>
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="100" autocomplete="name"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>

        <label class="block text-sm">
            <span class="font-medium">{{ __('site.form_channel') }} *</span>
            <select name="contact_channel" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                    onchange="this.dataset.touched = 1; document.getElementById('contact-field').style.display = this.value === 'telegram_self' ? 'none' : ''">
                @foreach (\App\Models\Inquiry::CHANNELS as $channel)
                    <option value="{{ $channel }}" @selected(old('contact_channel', $locale === 'vi' ? 'zalo' : 'whatsapp') === $channel)>{{ __('site.channels.'.$channel) }}</option>
                @endforeach
                <option value="telegram_self" data-tg-only @selected(old('contact_channel') === 'telegram_self')>{{ __('site.form_telegram_self') }}</option>
            </select>
        </label>

        <label class="block text-sm sm:col-span-2" id="contact-field">
            <span class="font-medium">{{ __('site.form_contact') }} *</span>
            <input type="text" name="contact" value="{{ old('contact') }}" maxlength="150" placeholder="{{ __('site.form_contact_placeholder') }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>

        <label class="block text-sm">
            <span class="font-medium">{{ __('site.form_arrival') }}</span>
            <input type="date" name="arrival_date" value="{{ old('arrival_date') }}" min="{{ now()->toDateString() }}"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>

        <label class="block text-sm">
            <span class="font-medium">{{ __('site.form_duration') }}</span>
            <input type="number" name="duration_months" value="{{ old('duration_months') }}" min="1" max="60" inputmode="numeric"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>

        <label class="block text-sm sm:col-span-2">
            <span class="font-medium">{{ __('site.form_budget') }}</span>
            <input type="number" name="budget" value="{{ old('budget') }}" min="0" step="any" inputmode="decimal"
                   class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
        </label>

        <label class="block text-sm sm:col-span-2">
            <span class="font-medium">{{ __('site.form_message') }}</span>
            <textarea name="message" rows="3" maxlength="2000" placeholder="{{ __('site.form_message_placeholder') }}"
                      class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('message') }}</textarea>
        </label>

        <label class="flex items-start gap-2 text-sm sm:col-span-2">
            <input type="checkbox" name="consent" value="1" required @checked(old('consent')) class="mt-1">
            <span>{{ __('site.form_consent', ['agency' => $agency, 'days' => $days]) }}</span>
        </label>

        <div class="sm:col-span-2">
            <button type="submit" class="site-accent w-full rounded-lg px-4 py-3 font-semibold sm:w-auto">{{ __('site.form_submit') }}</button>
        </div>
    </form>
</section>
