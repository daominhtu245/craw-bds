<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates only the batch envelope. Each item is validated individually by
 * IngestService so one bad item lands in `rejected` instead of failing the batch.
 */
class IngestItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.config('leadradar.ingest.max_batch')],
        ];
    }
}
