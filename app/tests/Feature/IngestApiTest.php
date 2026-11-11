<?php

namespace Tests\Feature;

use App\Jobs\PrefilterItemJob;
use App\Models\CollectorHeartbeat;
use App\Models\RawItem;
use App\Models\Source;
use App\Services\Ingest\IngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-ingest-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['leadradar.ingest.token' => self::TOKEN]);
        Queue::fake();
    }

    private function item(array $overrides = []): array
    {
        return array_merge([
            'source_type' => 'telegram',
            'source_identifier' => 'danang_test_group',
            'external_id' => '-1001234567890:42',
            'url' => 'https://t.me/danang_test_group/42',
            'author_key' => hash('sha256', 'telegram:111'),
            'author_handle' => 'test_user',
            'text' => 'Looking for a 1BR in An Thuong from December, 6 months, budget 450$',
            'posted_at' => '2026-11-09T03:00:00Z',
        ], $overrides);
    }

    private function postItems(array $items, ?string $token = self::TOKEN)
    {
        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

        return $this->postJson('/api/ingest/items', ['items' => $items], $headers);
    }

    public function test_it_stores_new_items_and_dispatches_prefilter(): void
    {
        $this->postItems([$this->item()])
            ->assertOk()
            ->assertExactJson(['accepted' => 1, 'duplicates' => 0, 'rejected' => []]);

        $this->assertDatabaseCount('raw_items', 1);
        $item = RawItem::first();
        $this->assertSame('new', $item->status->value);
        $this->assertSame(64, strlen($item->content_hash));
        $this->assertSame('2026-11-09 03:00:00', $item->posted_at->format('Y-m-d H:i:s'));

        Queue::assertPushedOn('prefilter', PrefilterItemJob::class, fn ($job) => $job->rawItemId === $item->id);
    }

    public function test_sending_the_same_item_twice_creates_one_record(): void
    {
        $this->postItems([$this->item()])->assertJson(['accepted' => 1, 'duplicates' => 0]);
        $this->postItems([$this->item()])->assertJson(['accepted' => 0, 'duplicates' => 1]);

        $this->assertDatabaseCount('raw_items', 1);
        Queue::assertPushed(PrefilterItemJob::class, 1);
    }

    public function test_duplicates_inside_one_batch_are_counted(): void
    {
        $this->postItems([$this->item(), $this->item()])
            ->assertJson(['accepted' => 1, 'duplicates' => 1]);

        $this->assertDatabaseCount('raw_items', 1);
    }

    public function test_wrong_or_missing_token_returns_401(): void
    {
        $this->postItems([$this->item()], 'wrong-token')->assertUnauthorized();
        $this->postItems([$this->item()], null)->assertUnauthorized();

        $this->assertDatabaseCount('raw_items', 0);
    }

    public function test_empty_configured_token_rejects_everything(): void
    {
        config(['leadradar.ingest.token' => '']);

        $this->postItems([$this->item()], '')->assertUnauthorized();
    }

    public function test_batch_over_50_items_returns_422(): void
    {
        $items = [];
        for ($i = 0; $i < 51; $i++) {
            $items[] = $this->item(['external_id' => "chat:{$i}"]);
        }

        $this->postItems($items)->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('raw_items', 0);
    }

    public function test_batch_of_exactly_50_items_is_accepted(): void
    {
        $items = [];
        for ($i = 0; $i < 50; $i++) {
            $items[] = $this->item(['external_id' => "chat:{$i}", 'text' => "Looking for apartment number {$i}"]);
        }

        $this->postItems($items)->assertOk()->assertJson(['accepted' => 50]);
    }

    public function test_invalid_items_are_rejected_individually(): void
    {
        $response = $this->postItems([
            $this->item(['external_id' => 'ok:1']),
            $this->item(['external_id' => 'bad:1', 'text' => '']),
            $this->item(['external_id' => 'bad:2', 'source_type' => 'myspace']),
            $this->item(['external_id' => 'bad:3', 'author_key' => 'not-a-hash']),
        ]);

        $response->assertOk()->assertJson(['accepted' => 1, 'duplicates' => 0]);
        $this->assertSame([1, 2, 3], array_column($response->json('rejected'), 'index'));
        $this->assertDatabaseCount('raw_items', 1);
    }

    public function test_reddit_and_threads_items_are_accepted(): void
    {
        $this->postItems([
            $this->item(['source_type' => 'reddit', 'source_identifier' => 'danang', 'external_id' => 't3_abc', 'url' => 'https://www.reddit.com/r/danang/comments/abc/x/']),
            $this->item(['source_type' => 'threads', 'source_identifier' => 'da nang apartment', 'external_id' => '123', 'url' => 'https://www.threads.com/@someone/post/ABC']),
        ])->assertOk()->assertJson(['accepted' => 2, 'rejected' => []]);

        $this->assertSame(['reddit', 'threads'], Source::orderBy('id')->get()->map(fn ($s) => $s->type->value)->all());
    }

    public function test_unknown_source_is_auto_created_and_last_item_at_updated(): void
    {
        $this->postItems([
            $this->item(['external_id' => 'a', 'posted_at' => '2026-11-09T01:00:00Z']),
            $this->item(['external_id' => 'b', 'posted_at' => '2026-11-09T02:00:00Z']),
        ]);

        $source = Source::firstWhere('identifier', 'danang_test_group');
        $this->assertNotNull($source);
        $this->assertTrue($source->is_active);
        $this->assertSame('2026-11-09 02:00:00', $source->last_item_at->format('Y-m-d H:i:s'));

        // Older backfilled items never move last_item_at backwards.
        $this->postItems([$this->item(['external_id' => 'c', 'posted_at' => '2026-11-07T00:00:00Z'])]);
        $this->assertSame('2026-11-09 02:00:00', $source->fresh()->last_item_at->format('Y-m-d H:i:s'));
    }

    public function test_same_external_id_in_different_sources_is_not_a_duplicate(): void
    {
        $this->postItems([
            $this->item(),
            $this->item(['source_identifier' => 'another_group']),
        ])->assertJson(['accepted' => 2]);
    }

    public function test_phone_and_email_are_masked_before_storage(): void
    {
        $this->postItems([$this->item([
            'text' => 'Need a flat near My Khe, call me +84 905 123 456 or mail test.user@example.com',
            'context_text' => 'Original post, owner phone 0905.123.456',
        ])]);

        $item = RawItem::first();
        $this->assertStringNotContainsString('905', $item->text);
        $this->assertStringNotContainsString('example.com', $item->text);
        $this->assertStringContainsString('[phone]', $item->text);
        $this->assertStringContainsString('[email]', $item->text);
        $this->assertStringContainsString('[phone]', $item->context_text);
    }

    public function test_raw_author_id_is_hashed_and_never_stored(): void
    {
        $item = $this->item(['author_id' => '987654321']);
        unset($item['author_key']);

        $this->postItems([$item])->assertJson(['accepted' => 1]);

        $stored = RawItem::first();
        $this->assertSame(IngestService::authorKey('telegram', '987654321'), $stored->author_key);
        $this->assertStringNotContainsString('987654321', json_encode($stored->getAttributes()));
    }

    public function test_context_is_truncated_to_1000_chars(): void
    {
        $this->postItems([$this->item(['context_text' => str_repeat('a', 1500)])]);

        $this->assertSame(1000, mb_strlen(RawItem::first()->context_text));
    }

    public function test_sources_endpoint_lists_only_active_sources_of_type(): void
    {
        Source::create(['type' => 'telegram', 'name' => 'A', 'identifier' => 'group_a', 'is_active' => true]);
        Source::create(['type' => 'telegram', 'name' => 'B', 'identifier' => 'group_b', 'is_active' => false]);
        Source::create(['type' => 'forum', 'name' => 'C', 'identifier' => 'https://forum.example', 'is_active' => true]);

        $this->getJson('/api/collector/sources?type=telegram', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.identifier', 'group_a');

        $this->getJson('/api/collector/sources?type=myspace', ['Authorization' => 'Bearer '.self::TOKEN])
            ->assertUnprocessable();

        $this->getJson('/api/collector/sources?type=telegram')->assertUnauthorized();
    }

    public function test_heartbeat_is_recorded_and_updated(): void
    {
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];

        $this->postJson('/api/collector/heartbeat', ['collector' => 'telegram', 'ok' => true], $headers)->assertOk();
        $this->travel(5)->minutes();
        $this->postJson('/api/collector/heartbeat', ['collector' => 'telegram', 'ok' => false], $headers)->assertOk();

        $this->assertDatabaseCount('collector_heartbeats', 1);
        $heartbeat = CollectorHeartbeat::first();
        $this->assertFalse($heartbeat->ok);
        $this->assertTrue($heartbeat->last_seen_at->isToday());

        $this->postJson('/api/collector/heartbeat', ['collector' => 'telegram'])->assertUnauthorized();
    }

    public function test_rate_limit_is_120_requests_per_minute(): void
    {
        $headers = ['Authorization' => 'Bearer '.self::TOKEN];

        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/api/collector/heartbeat', ['collector' => 'telegram'], $headers)->assertOk();
        }

        $this->postJson('/api/collector/heartbeat', ['collector' => 'telegram'], $headers)->assertTooManyRequests();
    }
}
