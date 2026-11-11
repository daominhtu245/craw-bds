<?php

namespace App\Http\Controllers;

use App\Http\Requests\InquiryRequest;
use App\Models\Listing;
use App\Models\Setting;
use App\Services\Site\InquiryService;
use App\Services\Site\ListingCatalog;
use App\Services\Site\TelegramWebApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

/**
 * Public apartment site and Telegram Mini App (App #2). Thin: logic lives in Services\Site.
 */
class SiteController extends Controller
{
    public function home(Request $request): RedirectResponse
    {
        abort_unless((bool) config('leadradar.site.enabled'), 404);

        $locales = (array) config('leadradar.site.locales');
        $locale = $request->getPreferredLanguage($locales) ?? config('leadradar.site.default_locale');

        return redirect()->route('site.index', ['locale' => $locale] + $request->only('ref'));
    }

    public function index(Request $request, ListingCatalog $catalog): View
    {
        $filters = $request->validate([
            'area' => ['nullable', 'string', 'max:64'],
            'bedrooms' => ['nullable', 'integer', 'min:0', 'max:10'],
            'max_price' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'project' => ['nullable', 'string', 'max:100'],
        ]);

        return view('site.index', [
            'listings' => $catalog->search($filters),
            'areas' => $catalog->areas(app()->getLocale()),
            'projects' => $catalog->projects(),
            'filters' => $filters,
        ] + $this->shared());
    }

    public function show(string $locale, string $listing): View
    {
        $model = Listing::query()->published()->with(['project', 'area'])->where('slug', $listing)->firstOrFail();

        return view('site.show', ['listing' => $model] + $this->shared());
    }

    public function inquiry(string $locale, InquiryRequest $request, InquiryService $inquiries, TelegramWebApp $webApp): RedirectResponse
    {
        $data = $request->validated();
        $telegramUser = $webApp->validate($data['tg_init_data'] ?? null);
        $data['ref'] = ($data['ref'] ?? null) ?: $request->session()->get('site_ref');

        // "My Telegram" only works inside the Mini App with a username Telegram vouches for.
        if ($data['contact_channel'] === 'telegram_self' && blank($telegramUser['username'] ?? null) && blank($data['contact'] ?? null)) {
            return back()->withInput()->withErrors(['contact' => __('validation.required', ['attribute' => __('site.form_contact')])]);
        }

        $inquiries->submit($data, $locale, $telegramUser, (string) $request->ip());

        return back()->with('inquiry_sent', true);
    }

    /** @return array<string, mixed> */
    private function shared(): array
    {
        return [
            'agency' => (string) (Setting::get('agency_name') ?: config('app.name')),
            'contacts' => array_filter((array) Setting::get('site_contacts', [])),
            'formStarted' => Crypt::encryptString((string) time()),
            'ref' => session('site_ref'),
        ];
    }
}
