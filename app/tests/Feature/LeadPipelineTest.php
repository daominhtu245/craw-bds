<?php

namespace Tests\Feature;

use App\Enums\RawItemStatus;
use App\Jobs\ClassifyItemJob;
use App\Jobs\NotifyLeadJob;
use App\Jobs\PrefilterItemJob;
use App\Models\Lead;
use App\Models\RawItem;
use App\Services\Ai\LeadClassifier;
use App\Services\Leads\LeadProcessor;
use App\Services\Notify\QuietHours;
use App\Services\Notify\TelegramNotifier;
use App\Services\Prefilter\Prefilter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakesClaude;
use Tests\TestCase;

/**
 * End to end with the sync queue: ingest → prefilter → classify (fake AI) →
 * match → score → Telegram (faked HTTP).
 */
class LeadPipelineTest extends TestCase
{
    use FakesClaude;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config([
            'leadradar.ingest.token' => 'tok',
            'leadradar.notify.bot_token' => 'BOT',
            'leadradar.notify.team_chat_id' => '-100123',
            'leadradar.notify.dashboard_lead_url' => 'https://radar.example.com/admin/leads/{id}',
        ]);
        $this->travelTo('2026-11-09 03:06:00');
    }

    private function ingest(string $text, string $externalId = 'chat:42'): void
    {
        $this->postJson('/api/ingest/items', ['items' => [[
            'source_type' => 'telegram',
            'source_identifier' => 'danang_forum',
            'external_id' => $externalId,
            'url' => 'https://t.me/danang_forum/42',
            'author_key' => hash('sha256', 'telegram:7'),
            'text' => $text,
            'posted_at' => '2026-11-09T03:00:00Z',
        ]]], ['Authorization' => 'Bearer tok'])->assertOk();
    }

    public function test_hot_lead_reaches_telegram(): void
    {
        $this->fakeClaude(['tenant_demand', $this->toolResult(['public_reply' => 'Hi! We can help.', 'dm' => 'Hello!'], 'claude-sonnet-5-5')]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->ingest('Looking for 1BR in An Thuong from Dec, 6 months, budget 450$, we have a cat');

        $lead = Lead::firstOrFail();
        // 10 tenant + 25 pre-arrival ≤60d + 20 duration + 15 budget + 10 area + 10 listing + 10 fresh = 100
        $this->assertSame(100, $lead->score);
        $this->assertNotNull($lead->notified_at);
        $this->assertSame('Sea Breeze 1BR', $lead->matchedListings->first()->title);
        $this->assertSame(RawItemStatus::Classified, RawItem::first()->status);
        $this->assertSame(2, $lead->replyDrafts()->count());

        Http::assertSent(function (Request $r) use ($lead) {
            $d = $r->data();

            return str_contains($r->url(), '/botBOT/sendMessage')
                && $d['chat_id'] === '-100123'
                && $d['parse_mode'] === 'HTML'
                && str_starts_with($d['text'], '🔥 Lead 100 điểm · 🇬🇧 EN · Telegram: danang_forum')
                && str_contains($d['text'], '⏱ Đăng 6 phút trước · Giai đoạn: chưa sang VN')
                && str_contains($d['text'], '🏠 Khớp')
                && str_contains($d['text'], 'tốt nhất: Sea Breeze 1BR – 420$')
                && $d['reply_markup']['inline_keyboard'][0][0] === ['text' => 'Mở bài gốc', 'url' => 'https://t.me/danang_forum/42']
                && $d['reply_markup']['inline_keyboard'][0][1]['url'] === "https://radar.example.com/admin/leads/{$lead->id}";
        });
    }

    public function test_low_score_lead_is_not_notified(): void
    {
        $low = $this->fixture('tenant_demand');
        $low = array_merge($low, ['stage' => 'unknown', 'duration_months' => null, 'budget' => null, 'areas_mentioned' => [], 'confidence' => 0.7]);
        $this->fakeClaude([$low]);
        Http::fake();

        $this->ingest('Looking for an apartment somewhere, any ideas?');

        $this->assertLessThan(60, Lead::firstOrFail()->score);
        $this->assertNull(Lead::first()->notified_at);
        Http::assertNothingSent();
    }

    public function test_leaving_handover_is_always_notified(): void
    {
        $this->fakeClaude(['leaving_handover']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->ingest('Leaving Vietnam, looking for someone to take over my lease in Son Tra, 2BR 550$/month');

        $this->assertSame(50, Lead::firstOrFail()->score);
        Http::assertSent(fn (Request $r) => str_starts_with($r->data()['text'], '♻️ Nguồn hàng'));
    }

    public function test_filtered_item_never_reaches_ai(): void
    {
        $this->fakeClaude([]);

        $this->ingest('Where can I buy a good motorbike helmet in Da Nang?');

        $this->assertSame(RawItemStatus::FilteredOut, RawItem::first()->status);
        $this->assertSame(0, Lead::count());
    }

    public function test_notify_job_never_sends_twice_and_releases_on_failure(): void
    {
        Queue::fake();
        $this->fakeClaude(['tenant_demand']);
        $this->ingest('Looking for 1BR in An Thuong from Dec, 6 months, budget 450$');
        Queue::assertPushed(PrefilterItemJob::class);

        // Run the pipeline by hand without the queue.
        $item = RawItem::first();
        (new PrefilterItemJob($item->id))->handle(app(Prefilter::class));
        (new ClassifyItemJob($item->id))->handle(app(LeadClassifier::class), app(LeadProcessor::class));
        $lead = Lead::firstOrFail();

        Http::fakeSequence('api.telegram.org/*')
            ->push(['ok' => false, 'description' => 'Bad Gateway'], 502)
            ->push(['ok' => true]);

        try {
            (new NotifyLeadJob($lead->id))->handle(app(TelegramNotifier::class), app(QuietHours::class));
            $this->fail('Expected failure');
        } catch (\RuntimeException) {
            $this->assertNull($lead->fresh()->notified_at);
        }

        (new NotifyLeadJob($lead->id))->handle(app(TelegramNotifier::class), app(QuietHours::class));
        (new NotifyLeadJob($lead->id))->handle(app(TelegramNotifier::class), app(QuietHours::class));

        $this->assertNotNull($lead->fresh()->notified_at);
        Http::assertSentCount(2);
    }
}
