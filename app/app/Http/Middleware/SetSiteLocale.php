<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public site (App #2): locale from the first URL segment (/vi, /en, /ru).
 * Also remembers a tracking code (?ref=) for the inquiry form.
 */
class SetSiteLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('leadradar.site.enabled'), 404);

        $locale = (string) $request->route('locale');
        abort_unless(in_array($locale, (array) config('leadradar.site.locales'), true), 404);

        App::setLocale($locale);
        URL::defaults(['locale' => $locale]);

        if ($request->filled('ref')) {
            $request->session()->put('site_ref', substr((string) $request->query('ref'), 0, 32));
        }

        return $next($request);
    }
}
