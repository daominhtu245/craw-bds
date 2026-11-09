<?php

namespace App\Providers;

use App\Services\Prefilter\LanguageDetector;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        RateLimiter::for('collector', function (Request $request) {
            $key = $request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip();

            return Limit::perMinute((int) config('leadradar.ingest.rate_limit_per_minute'))->by('collector:'.$key);
        });
    }
}
