<?php

namespace App\Console\Commands;

use App\Services\Ingest\IngestResult;
use App\Services\Ingest\IngestService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Loads items from a JSONL file without a collector (SPEC 19, Phase 1).
 *
 * Each line is an IngestItem (SPEC 6.1). Missing fields get local defaults so
 * the eval file format ({text, context, posted_at, ...}) can be loaded too:
 * external_id is derived from the line content, so re-running is idempotent.
 */
#[Signature('leadradar:ingest-file
    {path : JSONL file, absolute or relative to the app root}
    {--source-type=telegram : Default source_type for lines without one}
    {--source=local-samples : Default source_identifier for lines without one}')]
#[Description('Ingest items from a JSONL file (one IngestItem per line)')]
class IngestFileCommand extends Command
{
    public function handle(IngestService $ingest): int
    {
        $path = $this->resolvePath((string) $this->argument('path'));

        if ($path === null) {
            $this->error('File not found: '.$this->argument('path'));

            return self::FAILURE;
        }

        $items = [];
        $lineNumbers = [];
        $total = new IngestResult;

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                $this->warn('Line '.($i + 1).': invalid JSON, skipped.');
                $total->reject($i + 1, 'invalid JSON');

                continue;
            }

            $items[] = $this->withDefaults($decoded);
            $lineNumbers[] = $i + 1;
        }

        $batchSize = (int) config('leadradar.ingest.max_batch');

        foreach (array_chunk($items, $batchSize) as $chunkIndex => $chunk) {
            $result = $ingest->ingest($chunk);

            foreach ($result->rejected as $rejection) {
                $line = $lineNumbers[$chunkIndex * $batchSize + $rejection['index']];
                $this->warn("Line {$line}: {$rejection['error']}");
                $total->reject($line, $rejection['error']);
            }

            $total->accepted += $result->accepted;
            $total->duplicates += $result->duplicates;
        }

        $this->table(['accepted', 'duplicates', 'rejected'], [[
            $total->accepted, $total->duplicates, count($total->rejected),
        ]]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function withDefaults(array $item): array
    {
        $item['source_type'] ??= $this->option('source-type');
        $item['source_identifier'] ??= $this->option('source');
        $item['context_text'] ??= $item['context'] ?? null;
        $item['posted_at'] ??= Carbon::now()->toIso8601String();
        $item['external_id'] ??= 'file:'.substr(hash('sha256', ($item['text'] ?? '').'|'.$item['posted_at']), 0, 32);
        $item['url'] ??= rtrim((string) config('app.url'), '/').'/samples/'.$item['external_id'];

        unset($item['context'], $item['expected_intent'], $item['expected_stage']);

        return $item;
    }

    private function resolvePath(string $path): ?string
    {
        foreach ([$path, base_path($path)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
