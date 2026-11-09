<?php

use App\Http\Controllers\Api\CollectorController;
use App\Http\Controllers\Api\IngestController;
use Illuminate\Support\Facades\Route;

// Collector endpoints (SPEC 7). Throttle runs before auth to slow down token guessing.
Route::middleware(['throttle:collector', 'ingest.token'])->group(function () {
    Route::post('/ingest/items', [IngestController::class, 'store']);
    Route::get('/collector/sources', [CollectorController::class, 'sources']);
    Route::post('/collector/heartbeat', [CollectorController::class, 'heartbeat']);
});
