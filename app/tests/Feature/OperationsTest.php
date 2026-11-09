<?php

namespace Tests\Feature;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\RawItemStatus;
use App\Enums\UserRole;
use App\Filament\Resources\RawItems\Pages\ListRawItems;
use App\Jobs\ClassifyItemJob;
use App\Jobs\NotifyLeadJob;
use App\Models\CollectorHeartbeat;
use App\Models\KnownAgent;
use App\Models\Lead;
use App\Models\RawItem;
use App\Models\Setting;
use App\Models\Source;
use App\Services\Ai\AiBudget;
use App\Services\Ai\ClassificationSchema;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\LeadClassifier;
use App\Services\Leads\LeadProcessor;
use App\Services\Notify\QuietDigest;
use App\Services\Notify\QuietHours;
use App\Services\Notify\TelegramNotifier;
use App\Services\Ops\HealthChecker;
use App\Services\Privacy\AuthorDataEraser;
use Database\Seeders\SettingSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        config([
            'leadradar.notify.bot_token' => 'BOT',
            'leadradar.notify.team_chat_id' => '-100123',
            'leadradar.ingest.token' => 'tok',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function sentTexts(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'))
            ->map(fn ($pair) => $pair[0]->data()['text'])
            ->values()
            ->all();
    }

    // ------------------------------------------------------------ heartbeat

    public function test_telegram_collector_silent_for_30_minutes_alerts_team_once_and_recovers(): void
    {
        $this->travelTo('2026-11-09 03:00:00');
        $this->postJson('/api/collector/heartbeat', ['collector' => 'telegram', 'ok' => true], ['Authorization' => 'Bearer tok'])->assertOk();

        $this->travel(29)->minutes();
        $this->artisan('leadradar:health')->assertSuccessful();
        $this->assertSame([], $this->sentTexts());

        // Collector stopped: 31 minutes without heartbeat.
        $this->travel(2)->minutes();
        $this->artisan('leadradar:health')->assertSuccessful();
        $this->artisan('leadradar:health')->assertSuccessful();   // no duplicate alert

        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('⚠️ Collector telegram không gửi heartbeat từ 10:00 09/11', $texts[0]);

        // Collector back.
        $this->postJson('/api/collector/heartbeat', ['collector' => 'telegram', 'ok' => true], ['Authorization' => 'Bearer tok'])->assertOk();
        app(HealthChecker::class)->check();
        $this->assertStringContainsString('✅ Collector telegram đã hoạt động lại', $this->sentTexts()[1]);
    }

    public function test_collector_reporting_not_ok_alerts(): void
    {
        CollectorHeartbeat::create(['collector' => 'facebook', 'ok' => false, 'last_seen_at' => now()]);

        $this->assertSame(1, app(HealthChecker::class)->check()['alerts']);
        $this->assertStringContainsString('facebook báo lỗi', $this->sentTexts()[0]);
    }

    public function test_important_source_without_items_for_24h_alerts(): void
    {
        Source::create(['type' => 'telegram', 'name' => 'Quiet group', 'identifier' => 'quiet', 'is_active' => true, 'meta' => ['important' => true], 'last_item_at' => now()->subHours(25)]);
        Source::create(['type' => 'telegram', 'name' => 'Not important', 'identifier' => 'other', 'is_active' => true, 'last_item_at' => now()->subDays(5)]);
        Source::create(['type' => 'telegram', 'name' => 'Fresh important', 'identifier' => 'fresh', 'is_active' => true, 'meta' => ['important' => true], 'last_item_at' => now()->subHour()]);

        $this->assertSame(1, app(HealthChecker::class)->check()['alerts']);
        // Quotes are HTML-escaped for Telegram's HTML parse mode.
        $this->assertStringContainsString('&quot;Quiet group&quot; không có item mới quá 24 giờ', $this->sentTexts()[0]);
    }

    // --------------------------------------------------------------- budget

    public function test_claude_calls_are_counted_against_the_daily_budget(): void
    {
        config(['leadradar.ai.api_key' => 'k', 'leadradar.ai.daily_budget_usd' => 0.001]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'name' => ClassificationSchema::TOOL_NAME, 'input' => ['intent' => 'other']]],
            'usage' => ['input_tokens' => 5000, 'output_tokens' => 1000],   // $0.0005 + $0.0005
        ])]);
        $budget = app(AiBudget::class);

        app(ClaudeClient::class)->callTool('claude-haiku-5-5', 's', 'u', ClassificationSchema::tool(), 'low', 100);

        $this->assertEqualsWithDelta(0.001, $budget->spentToday(), 1e-9);
        $this->assertTrue($budget->exceeded());
    }

    public function test_ai_job_pauses_and_alerts_when_budget_is_spent(): void
    {
        config(['leadradar.ai.daily_budget_usd' => 1.0]);
        Setting::put('classifier_mode', 'ai');   // "auto" would switch to keyword rules instead
        app(AiBudget::class)->record('claude-sonnet-5-5', 400_000, 30_000);   // $0.80 + $0.30
        $item = $this->makeLead([], ['status' => RawItemStatus::Queued])->rawItem;
        $item->lead()->delete();

        $classifier = Mockery::mock(LeadClassifier::class);
        $classifier->shouldNotReceive('classify');

        (new ClassifyItemJob($item->id))->handle($classifier, app(LeadProcessor::class));
        (new ClassifyItemJob($item->id))->handle($classifier, app(LeadProcessor::class));

        $this->assertSame(RawItemStatus::Queued, $item->fresh()->status);
        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('ngân sách AI', $texts[0]);
    }

    // ---------------------------------------------------------- quiet hours

    public function test_quiet_hours_parsing(): void
    {
        $quiet = app(QuietHours::class);
        Setting::put('quiet_hours', '23-07');

        $this->assertTrue($quiet->isQuiet(now()->setTimezone('Asia/Ho_Chi_Minh')->setTime(23, 30)));
        $this->assertTrue($quiet->isQuiet(now()->setTimezone('Asia/Ho_Chi_Minh')->setTime(6, 59)));
        $this->assertFalse($quiet->isQuiet(now()->setTimezone('Asia/Ho_Chi_Minh')->setTime(7, 0)));
        $this->assertFalse($quiet->isQuiet(now()->setTimezone('Asia/Ho_Chi_Minh')->setTime(14, 0)));

        Setting::put('quiet_hours', '12-14');
        $this->assertTrue($quiet->isQuiet(now()->setTimezone('Asia/Ho_Chi_Minh')->setTime(13, 0)));

        Setting::put('quiet_hours', 'off');
        $this->assertFalse($quiet->isQuiet(now()->setTimezone('Asia/Ho_Chi_Minh')->setTime(23, 30)));
    }

    public function test_quiet_hours_hold_normal_leads_send_urgent_and_digest_afterwards(): void
    {
        $this->travelTo('2026-11-09 17:00:00');   // 00:00 in Vietnam: quiet
        $normal = $this->makeLead(['score' => 80, 'stage' => LeadStage::PreArrival]);
        $urgent = $this->makeLead(['score' => 90, 'stage' => LeadStage::Urgent]);
        $handover = $this->makeLead(['score' => 50, 'intent' => LeadIntent::LeavingHandover, 'stage' => LeadStage::Unknown]);
        $cold = $this->makeLead(['score' => 20]);

        foreach ([$normal, $urgent, $handover] as $lead) {
            (new NotifyLeadJob($lead->id))->handle(app(TelegramNotifier::class), app(QuietHours::class));
        }

        $this->assertNull($normal->fresh()->notified_at);
        $this->assertNull($handover->fresh()->notified_at);
        $this->assertNotNull($urgent->fresh()->notified_at);
        $this->assertCount(1, $this->sentTexts());

        // Still quiet: digest does nothing.
        $this->assertSame(0, app(QuietDigest::class)->sendIfDue());

        // 07:05 in Vietnam: one summary with the held-back leads only.
        $this->travelTo('2026-11-10 00:05:00');
        $this->artisan('leadradar:quiet-digest')->expectsOutputToContain('leads in digest: 2')->assertSuccessful();

        $digest = $this->sentTexts()[1];
        $this->assertStringStartsWith('🌙 Tóm tắt lead trong giờ yên lặng: 2 lead', $digest);
        $this->assertStringContainsString($normal->summary_vi, $digest);
        $this->assertStringNotContainsString($cold->summary_vi, $digest);
        $this->assertNotNull($normal->fresh()->notified_at);
        $this->assertNull($cold->fresh()->notified_at);

        // Never twice.
        $this->assertSame(0, app(QuietDigest::class)->sendIfDue());
        $this->assertCount(2, $this->sentTexts());
    }

    // ------------------------------------------------------ erase by author

    public function test_erase_all_data_of_one_author(): void
    {
        $target = $this->makeLead([], ['author_handle' => 'target_user', 'author_key' => hash('sha256', 'telegram:1')]);
        // Same person, other message without handle but same author_key.
        $sameKey = $this->makeLead([], ['author_handle' => null, 'author_key' => hash('sha256', 'telegram:1')]);
        KnownAgent::create(['author_key' => hash('sha256', 'telegram:1'), 'reason' => 'x']);
        $other = $this->makeLead([], ['author_handle' => 'someone_else']);

        $this->assertSame(['raw_items' => 2, 'leads' => 2], app(AuthorDataEraser::class)->preview('@target_user'));
        app(AuthorDataEraser::class)->erase('@target_user');

        $this->assertModelMissing($target);
        $this->assertModelMissing($sameKey);
        $this->assertSame(0, KnownAgent::count());
        $this->assertModelExists($other);
    }

    public function test_admin_erases_author_from_dashboard(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $lead = $this->makeLead([], ['author_handle' => 'target_user']);
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(ListRawItems::class)
            ->callAction('eraseAuthor', ['author_handle' => 'target_user'])
            ->assertHasNoActionErrors();

        $this->assertModelMissing($lead);
        $this->assertSame(0, RawItem::count());
        $this->assertSame(0, Lead::count());
    }

    public function test_alert_messages_go_to_team_chat_as_html(): void
    {
        CollectorHeartbeat::create(['collector' => 'forum', 'ok' => true, 'last_seen_at' => now()->subHour()]);
        app(HealthChecker::class)->check();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/botBOT/sendMessage') && $r->data()['chat_id'] === '-100123' && $r->data()['parse_mode'] === 'HTML');
    }
}
