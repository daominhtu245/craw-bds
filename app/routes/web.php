<?php

use App\Http\Controllers\SiteController;
use App\Http\Middleware\SetSiteLocale;
use Illuminate\Support\Facades\Route;

// App #2: public apartment site, also opened as a Telegram Mini App.
Route::get('/', [SiteController::class, 'home'])->name('site.home');

Route::prefix('{locale}')
    ->where(['locale' => 'vi|en|ru'])
    ->middleware(SetSiteLocale::class)
    ->group(function () {
        Route::get('/', [SiteController::class, 'index'])->name('site.index');
        Route::get('/apartments/{listing}', [SiteController::class, 'show'])->name('site.listing');
        Route::post('/inquiry', [SiteController::class, 'inquiry'])->middleware('throttle:inquiries')->name('site.inquiry');
    });
