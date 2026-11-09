<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CollectorHeartbeat;
use App\Models\Source;
use App\Services\Ingest\IngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectorController extends Controller
{
    public function sources(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', IngestService::INGEST_SOURCE_TYPES)],
        ]);

        $sources = Source::query()
            ->where('type', $validated['type'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'type', 'name', 'identifier', 'language_hint', 'last_item_at', 'meta']);

        return response()->json(['data' => $sources]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'collector' => ['required', 'string', 'max:32', 'alpha_dash'],
            'ok' => ['sometimes', 'boolean'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ]);

        CollectorHeartbeat::query()->updateOrCreate(
            ['collector' => $validated['collector']],
            [
                'ok' => $validated['ok'] ?? true,
                'last_seen_at' => now(),
                'meta' => $validated['meta'] ?? null,
            ],
        );

        return response()->json(['status' => 'ok']);
    }
}
