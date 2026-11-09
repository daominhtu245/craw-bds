<?php

namespace App\Services\Privacy;

use App\Models\KnownAgent;
use App\Models\RawItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Xóa toàn bộ dữ liệu người này" (SPEC 15): deletes every item by an author
 * handle (and every other item with the same author_key), with their leads,
 * drafts, matches and activities.
 */
class AuthorDataEraser
{
    /** @return array{raw_items: int, leads: int} */
    public function preview(string $handle): array
    {
        $query = $this->query($handle);

        return [
            'raw_items' => (clone $query)->count(),
            'leads' => (clone $query)->whereHas('lead')->count(),
        ];
    }

    /** @return array{raw_items: int, leads: int} */
    public function erase(string $handle, ?int $byUserId = null): array
    {
        $preview = $this->preview($handle);

        DB::transaction(function () use ($handle) {
            $keys = $this->query($handle)->whereNotNull('author_key')->distinct()->pluck('author_key');
            $this->query($handle)->delete();
            KnownAgent::query()->whereIn('author_key', $keys)->delete();
        });

        // Log counts only, never the handle itself.
        Log::warning('privacy.author_erased', $preview + ['by_user' => $byUserId]);

        return $preview;
    }

    private function query(string $handle)
    {
        $handle = ltrim(trim($handle), '@');
        $keys = RawItem::query()->where('author_handle', $handle)->whereNotNull('author_key')->distinct()->pluck('author_key');

        return RawItem::query()->where(function ($q) use ($handle, $keys) {
            $q->where('author_handle', $handle);
            if ($keys->isNotEmpty()) {
                $q->orWhereIn('author_key', $keys);
            }
        });
    }
}
