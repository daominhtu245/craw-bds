<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IngestItemsRequest;
use App\Services\Ingest\IngestService;
use Illuminate\Http\JsonResponse;

class IngestController extends Controller
{
    public function store(IngestItemsRequest $request, IngestService $ingest): JsonResponse
    {
        return response()->json($ingest->ingest($request->input('items'))->toArray());
    }
}
