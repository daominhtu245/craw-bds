<?php

namespace App\Providers;

use App\Services\Prefilter\LanguageDetector;
use App\Services\Settings\ConfigOverrides;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LanguageDetector::class, fn () => new LanguageDetector(
            (float) config('leadradar.prefilter.vi_word_ratio'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Settings page values override config/.env (ConfigOverrides::FIELDS). Workers
        // re-apply before each job so a change needs no restart.
        ConfigOverrides::boot();
        Queue::before(fn () => ConfigOverrides::apply());

        RateLimiter::for('collector', function (Request $request) {
            $key = $request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip();

            return Limit::perMinute((int) config('leadradar.ingest.rate_limit_per_minute'))->by('collector:'.$key);
        });

        // Public inquiry form (App #2): a few requests per visitor per hour.
        RateLimiter::for('inquiries', fn (Request $request) => Limit::perHour((int) config('leadradar.site.inquiry_rate_limit_per_hour'))
            ->by('inquiry:'.$request->ip()));
    }
}
