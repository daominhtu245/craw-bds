<?php

namespace Tests\Feature;

use App\Jobs\PrefilterItemJob;
use App\Models\RawItem;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestFileCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->path = tempnam(sys_get_temp_dir(), 'samples').'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function writeLines(array $lines): void
    {
        file_put_contents($this->path, implode("\n", $lines)."\n");
    }

    public function test_it_ingests_full_and_minimal_lines_idempotently(): void
    {
        $this->writeLines([
            json_encode([
                'source_type' => 'forum', 'source_identifier' => 'https://forum.example/da-nang',
                'external_id' => 'thread-1', 'url' => 'https://forum.example/thread-1',
                'text' => 'Moving to Da Nang in January with two kids, which area is best?',
                'posted_at' => '2026-11-08T10:00:00Z',
            ]),
            // Eval-style line: no source, id or url.
            json_encode([
                'text' => 'Ищем квартиру на полгода в Сон Тра, бюджет 15 млн донгов',
                'context' => null, 'posted_at' => '2026-11-08T11:00:00Z',
                'expected_intent' => 'tenant_demand', 'expected_stage' => 'unknown',
            ]),
            '',
            '{not json',
        ]);

        $this->artisan('leadradar:ingest-file', ['path' => $this->path])->assertSuccessful();

        $this->assertDatabaseCount('raw_items', 2);
        $this->assertNotNull(Source::firstWhere('identifier', 'local-samples'));
        $this->assertStringStartsWith('file:', RawItem::query()->where('external_id', 'like', 'file:%')->value('external_id'));
        Queue::assertPushed(PrefilterItemJob::class, 2);

        // Second run: everything is a duplicate.
        $this->artisan('leadradar:ingest-file', ['path' => $this->path])->assertSuccessful();
        $this->assertDatabaseCount('raw_items', 2);
    }

    public function test_missing_file_fails(): void
    {
        $this->artisan('leadradar:ingest-file', ['path' => 'does/not/exist.jsonl'])->assertFailed();
    }

    public function test_bundled_sample_file_loads(): void
    {
        $this->artisan('leadradar:ingest-file', ['path' => 'storage/samples.jsonl'])->assertSuccessful();

        $this->assertGreaterThanOrEqual(10, RawItem::count());
        $this->assertSame(0, RawItem::query()->where('text', 'like', '%@%.%')->count());
    }
}
