<?php

namespace App\Services\Ingest;

use App\Enums\RawItemStatus;
use App\Jobs\PrefilterItemJob;
use App\Models\RawItem;
use App\Models\Source;
use App\Services\Privacy\PiiMasker;
use App\Services\Text\TextNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Stores collector items idempotently (SPEC 7).
 *
 * Each item is validated on its own so one bad item does not reject the batch.
 * Raw author IDs are never stored: if a client sends `author_id` instead of a
 * precomputed `author_key`, it is hashed and discarded.
 */
class IngestService
{
    /** Source types collectors may send (SPEC 6.1). Reddit is not ingested yet. */
    public const INGEST_SOURCE_TYPES = ['telegram', 'facebook', 'forum'];

    /** @var array<string, Source> */
    private array $sourceCache = [];

    public function __construct(
        private readonly PiiMasker $masker,
        private readonly TextNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, mixed>  $items
     */
    public function ingest(array $items): IngestResult
    {
        $result = new IngestResult;
        $newestBySource = [];

        foreach (array_values($items) as $index => $item) {
            $validator = Validator::make(is_array($item) ? $item : [], self::rules());

            if ($validator->fails()) {
                $result->reject($index, $validator->errors()->first());

                continue;
            }

            $data = $validator->validated();
            $source = $this->resolveSource($data['source_type'], $data['source_identifier']);
            $rawItemId = $this->store($source, $data);

            if ($rawItemId === null) {
                $result->duplicates++;

                continue;
            }

            $result->accepted++;
            $postedAt = Carbon::parse($data['posted_at'])->utc();
            $newestBySource[$source->id] = max($newestBySource[$source->id] ?? $postedAt, $postedAt);

            PrefilterItemJob::dispatch($rawItemId);
        }

        $this->touchSources($newestBySource);

        return $result;
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'source_type' => ['required', 'string', 'in:'.implode(',', self::INGEST_SOURCE_TYPES)],
            'source_identifier' => ['required', 'string', 'max:255'],
            'external_id' => ['required', 'string', 'max:191'],
            'parent_external_id' => ['nullable', 'string', 'max:191'],
            'url' => ['required', 'string', 'url', 'max:1024'],
            'author_id' => ['nullable', 'string', 'max:255'],
            'author_key' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'author_handle' => ['nullable', 'string', 'max:191'],
            'text' => ['required', 'string', 'max:50000'],
            'context_text' => ['nullable', 'string'],
            'posted_at' => ['required', 'date'],
        ];
    }

    /**
     * sha256(source_type + ":" + raw author id). Collectors must use the same formula.
     */
    public static function authorKey(string $sourceType, string $authorId): string
    {
        return hash('sha256', $sourceType.':'.$authorId);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return int|null The new raw_item id, or null when it already existed.
     */
    private function store(Source $source, array $data): ?int
    {
        $text = $this->masker->mask($data['text']);
        $context = $this->masker->mask($data['context_text'] ?? null);

        if ($context !== null) {
            $context = mb_substr($context, 0, (int) config('leadradar.ingest.context_max_chars'));
        }

        $authorKey = $data['author_key'] ?? null;
        if ($authorKey === null && ! empty($data['author_id'])) {
            $authorKey = self::authorKey($data['source_type'], $data['author_id']);
        }

        $now = now();
        $inserted = RawItem::query()->insertOrIgnore([
            'source_id' => $source->id,
            'external_id' => $data['external_id'],
            'parent_external_id' => $data['parent_external_id'] ?? null,
            'url' => $data['url'],
            'author_handle' => $data['author_handle'] ?? null,
            'author_key' => $authorKey,
            'text' => $text,
            'context_text' => $context,
            'content_hash' => $this->normalizer->contentHash($text),
            'posted_at' => Carbon::parse($data['posted_at'])->utc(),
            'status' => RawItemStatus::New->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return null;
        }

        $id = (int) RawItem::query()
            ->where('source_id', $source->id)
            ->where('external_id', $data['external_id'])
            ->value('id');

        Log::info('raw_item.ingested', [
            'source' => $source->identifier,
            'item_id' => $id,
            'excerpt' => TextNormalizer::excerpt($text),
        ]);

        return $id;
    }

    private function resolveSource(string $type, string $identifier): Source
    {
        $cacheKey = $type.'|'.$identifier;

        if (isset($this->sourceCache[$cacheKey])) {
            return $this->sourceCache[$cacheKey];
        }

        $source = Source::query()->firstOrCreate(
            ['type' => $type, 'identifier' => $identifier],
            ['name' => $identifier, 'is_active' => true],
        );

        if ($source->wasRecentlyCreated) {
            Log::warning('source.auto_created', ['source' => $identifier, 'type' => $type]);
        }

        return $this->sourceCache[$cacheKey] = $source;
    }

    /**
     * last_item_at only moves forward, so backfilled old items do not hide a dead source.
     *
     * @param  array<int, Carbon>  $newestBySource
     */
    private function touchSources(array $newestBySource): void
    {
        foreach ($newestBySource as $sourceId => $newest) {
            Source::query()
                ->whereKey($sourceId)
                ->where(fn ($q) => $q->whereNull('last_item_at')->orWhere('last_item_at', '<', $newest))
                ->update(['last_item_at' => $newest]);
        }
    }
}
