<?php

namespace Tests\Feature;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\PropertyType;
use App\Enums\RawItemStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\PrefilterTester;
use App\Jobs\ClassifyItemJob;
use App\Jobs\DraftReplyJob;
use App\Models\Keyword;
use App\Models\Lead;
use App\Models\RawItem;
use App\Models\Setting;
use App\Services\Ai\AiBudget;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\ClaudeException;
use App\Services\Ai\LeadClassifier;
use App\Services\Ai\ReplyDrafter;
use App\Services\Classify\ClassifierMode;
use App\Services\Classify\FieldExtractor;
use App\Services\Classify\RuleClassifier;
use App\Services\Leads\LeadProcessor;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesLeads;
use Tests\Concerns\FakesClaude;
use Tests\TestCase;

/**
 * Keyword classifier and the AI / keyword mode switch.
 */
class KeywordClassifierTest extends TestCase
{
    use CreatesLeads;
    use FakesClaude;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Http::preventStrayRequests();
    }

    private function rules(string $text, string $postedAt = '2026-11-09T03:00:00Z'): array
    {
        return app(RuleClassifier::class)->analyze($text, Carbon::parse($postedAt));
    }

    // ------------------------------------------------------------- fields

    public static function budgets(): array
    {
        return [
            'usd suffix' => ['budget up to 450 USD', null, 450.0, 'USD'],
            'usd prefix range' => ['between $400-500 per month', 400.0, 500.0, 'USD'],
            'dollar sign suffix' => ['around 350$/month', null, 350.0, 'USD'],
            'k suffix' => ['up to 1.2k$', null, 1200.0, 'USD'],
            'vnd million' => ['бюджет до 12 млн донгов', null, 12_000_000.0, 'VND'],
            'vnd range' => ['бюджет 15-18 млн', 15_000_000.0, 18_000_000.0, 'VND'],
            'vnd thousands' => ['8.000.000 VND per month', null, 8_000_000.0, 'VND'],
            'russian dollars' => ['около 600 долларов', null, 600.0, 'USD'],
            'plain budget' => ['budget 500, 6 months', null, 500.0, 'USD'],
            'from' => ['from 900$', 900.0, null, 'USD'],
        ];
    }

    #[DataProvider('budgets')]
    public function test_budget(string $text, ?float $min, ?float $max, string $currency): void
    {
        $b = app(FieldExtractor::class)->budget($text);

        $this->assertNotNull($b);
        $this->assertSame([$min, $max, $currency], [$b['min'], $b['max'], $b['currency']]);
    }

    public function test_no_budget_from_unrelated_numbers(): void
    {
        $this->assertNull(app(FieldExtractor::class)->budget('We are 2 adults with 1 kid, 6 months'));
    }

    public function test_duration_property_type_and_short_stay(): void
    {
        $f = app(FieldExtractor::class);

        $this->assertSame(6, $f->durationMonths('looking for 6 months'));
        $this->assertSame(12, $f->durationMonths('for a year'));
        $this->assertSame(24, $f->durationMonths('2 years contract'));
        $this->assertSame(6, $f->durationMonths('на полгода'));
        $this->assertSame(4, $f->durationMonths('на 4 месяца'));
        $this->assertNull($f->durationMonths('para months'));   // "a" inside a word is not "one"

        $this->assertSame([PropertyType::OneBr, 1], $f->propertyType('need a 1 bedroom'));
        $this->assertSame([PropertyType::TwoBr, 2], $f->propertyType('2BR near the river'));
        $this->assertSame([PropertyType::House, 3], $f->propertyType('3 bedroom house'));
        $this->assertSame([PropertyType::Studio, 0], $f->propertyType('ищем студию'));
        $this->assertSame([PropertyType::OneBr, 1], $f->propertyType('ищем однушку'));
        $this->assertSame([PropertyType::Unknown, null], $f->propertyType('any apartment'));

        $this->assertTrue($f->mentionsShortStay('for 5 nights next week'));
        $this->assertFalse($f->mentionsShortStay('been here two weeks, want a place for 6 months'));
    }

    public function test_arrival_date(): void
    {
        $f = app(FieldExtractor::class);
        $posted = Carbon::parse('2026-11-09T03:00:00Z');

        $this->assertSame('2026-12-15', $f->arrivalDate('moving on December 15', $posted));
        $this->assertSame('2026-12-03', $f->arrivalDate('we land on December 3rd', $posted));
        $this->assertSame('2027-01-01', $f->arrivalDate('arriving in January', $posted));
        $this->assertSame('2026-12-10', $f->arrivalDate('приезжаем 10 декабря', $posted));
        $this->assertSame('2026-12-01', $f->arrivalDate('from Dec, 6 months', $posted));   // not day 6
        $this->assertSame('2027-05-01', $f->arrivalDate('arriving in May', $posted));
        $this->assertNull($f->arrivalDate('we may need a bigger place', $posted));
        $this->assertNull($f->arrivalDate('I decided to rent', $posted));          // "dec" ≠ "decided"
    }

    // ------------------------------------------------------------- intents

    public function test_tenant_demand_with_fields(): void
    {
        $r = $this->rules('Hi! Couple with a cat moving to Da Nang on December 15. Looking for a 1 bedroom in An Thuong for 6 months, budget up to 450 USD, kitchen please.');

        $this->assertSame('tenant_demand', $r['intent']);
        $this->assertSame('pre_arrival', $r['stage']);
        $this->assertSame('2026-12-15', $r['arrival_date']);
        $this->assertSame(6, $r['duration_months']);
        $this->assertSame('1br', $r['property_type']);
        $this->assertSame(['an-thuong'], $r['areas_mentioned']);
        $this->assertSame(450.0, $r['budget']['max']);
        $this->assertContains('pet_friendly', $r['requirements']);
        $this->assertContains('kitchen', $r['requirements']);
        $this->assertSame('couple, with pets', $r['household']);
        $this->assertGreaterThanOrEqual(0.6, $r['confidence']);
        $this->assertSame('Tìm căn 1 PN ở An Thượng, từ 15/12, 6 tháng, ngân sách 450 usd, cặp đôi, có thú cưng', $r['summary_vi']);
    }

    public static function intents(): array
    {
        return [
            'urgent' => ['Need a place ASAP, my booking got cancelled, studio near the beach for 3 months', 'tenant_demand', 'urgent'],
            'in city' => ['Already in Da Nang, searching for a long term 2BR in Hai Chau', 'tenant_demand', 'in_city_searching'],
            'russian' => ['Ищем квартиру на полгода в Сон Тра, бюджет до 15 млн', 'tenant_demand', 'unknown'],
            'handover' => ['Leaving Vietnam, looking for someone to take over my lease, 1BR in My Khe 400$', 'leaving_handover', 'unknown'],
            'landlord' => ['Renting out my 2BR apartment in Son Tra, 600 USD, available from December', 'landlord_offer', 'unknown'],
            'landlord ru' => ['Сдаю студию у моря, 7 млн в месяц', 'landlord_offer', 'unknown'],
            'agent' => ['Real estate agent here, no fee for tenants, many apartments for rent', 'agent', 'unknown'],
            'short term' => ['Need a hotel in Da Nang for 5 nights next week, any recommendations?', 'short_term', 'unknown'],
            'other city' => ['Looking for a 2 bedroom apartment in Hanoi for a year', 'other_city', 'unknown'],
            'leaving da nang' => ['Ищем дом в Нячанге на год, переезжаем из Дананга', 'other_city', 'unknown'],
            'not housing' => ['Looking for a good dentist in Da Nang', 'other', 'unknown'],
            'nothing' => ['What is the weather like this week?', 'other', 'unknown'],
        ];
    }

    #[DataProvider('intents')]
    public function test_intent_and_stage(string $text, string $intent, string $stage): void
    {
        $r = $this->rules($text);

        $this->assertSame([$intent, $stage], [$r['intent'], $r['stage']], implode(', ', $r['matched']));
    }

    public function test_keywords_edited_on_dashboard_change_the_result(): void
    {
        $this->assertSame('other', $this->rules('Who can sort out a flat swap in Hai Chau?')['intent']);

        Keyword::create(['language' => 'en', 'kind' => 'intent_tenant', 'term' => 'flat swap', 'weight' => 2, 'is_active' => true]);

        $this->assertSame('tenant_demand', $this->rules('Who can sort out a flat swap in Hai Chau?')['intent']);
    }

    public function test_eval_command_keyword_engine_runs_without_api(): void
    {
        $this->artisan('leadradar:eval', ['--engine' => 'keyword'])
            ->expectsOutputToContain('engine: keyword')
            ->expectsOutputToContain('estimated cost: $0.0000')
            ->expectsOutputToContain('PASS')
            ->assertSuccessful();
    }

    // ---------------------------------------------------------------- modes

    private function queuedItem(string $text): RawItem
    {
        $item = $this->makeLead([], ['status' => RawItemStatus::Queued, 'text' => $text, 'posted_at' => now()->subHour()])->rawItem;
        $item->lead()->delete();

        return $item->fresh();
    }

    private function runJob(RawItem $item): void
    {
        (new ClassifyItemJob($item->id))->handle(app(LeadClassifier::class), app(LeadProcessor::class));
    }

    public function test_keyword_mode_creates_lead_without_ai_and_without_drafts(): void
    {
        Queue::fake([DraftReplyJob::class]);
        config(['leadradar.ai.api_key' => 'k']);   // even with a key
        Setting::put('classifier_mode', 'keyword');
        $this->app->instance(ClaudeClient::class, Mockery::mock(ClaudeClient::class)->shouldNotReceive('callTool')->getMock());
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $item = $this->queuedItem('Looking for a 1BR in An Thuong from December, 6 months, budget 450$');
        $this->runJob($item);

        $lead = Lead::query()->where('raw_item_id', $item->id)->firstOrFail();
        $this->assertSame(LeadIntent::TenantDemand, $lead->intent);
        $this->assertSame(['an-thuong'], $lead->areas);
        $this->assertSame(450, $lead->budget_max_usd);
        $this->assertSame('keyword-rules-v1', $item->fresh()->ai_model);
        $this->assertSame('keyword', $item->fresh()->classification['engine']);
        $this->assertSame(RawItemStatus::Classified, $item->fresh()->status);
        $this->assertSame('Lead được tạo tự động bằng keyword.', $lead->activities()->first()->body);
        Queue::assertNotPushed(DraftReplyJob::class);
    }

    public function test_auto_mode_without_key_uses_keywords(): void
    {
        Queue::fake([DraftReplyJob::class]);
        config(['leadradar.ai.api_key' => '']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->assertSame('auto', app(ClassifierMode::class)->mode());
        $this->assertFalse(app(ClassifierMode::class)->useAi());

        $item = $this->queuedItem('Ищем однушку в Ан Тхыонг на полгода, бюджет до 12 млн донгов');
        $this->runJob($item);

        $this->assertNotNull(Lead::query()->where('raw_item_id', $item->id)->first());
        Queue::assertNotPushed(DraftReplyJob::class);
    }

    public function test_auto_mode_switches_to_keywords_when_budget_is_spent_and_alerts_once(): void
    {
        config(['leadradar.ai.daily_budget_usd' => 1.0, 'leadradar.notify.bot_token' => 'BOT', 'leadradar.notify.team_chat_id' => '-1']);
        $this->fakeClaude([]);   // sets a key; callTool must never run
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        app(AiBudget::class)->record('claude-sonnet-5-5', 400_000, 30_000);   // $1.10

        $this->runJob($this->queuedItem('Looking for a studio in My Khe, 3 months, 350$'));
        $this->runJob($this->queuedItem('Looking for a 2BR in Hai Chau, a year, 600$'));

        $this->assertSame(2, Lead::count());
        $alerts = collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]['text'] ?? '', 'ngân sách AI'));
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('Chuyển sang phân loại bằng keyword', $alerts->first()[0]['text']);
    }

    public function test_auto_mode_falls_back_to_keywords_when_ai_fails(): void
    {
        config(['leadradar.ai.api_key' => 'k']);
        $mock = Mockery::mock(ClaudeClient::class);
        $mock->shouldReceive('callTool')->once()->andThrow(new ClaudeException('HTTP 401'));
        $this->app->instance(ClaudeClient::class, $mock);
        Queue::fake();

        $item = $this->queuedItem('Looking for a 1BR in An Thuong, 6 months, 450$');
        $this->runJob($item);

        $this->assertSame('keyword-rules-v1', $item->fresh()->ai_model);
        $this->assertNotNull(Lead::query()->where('raw_item_id', $item->id)->first());
    }

    public function test_ai_mode_does_not_fall_back(): void
    {
        config(['leadradar.ai.api_key' => '']);
        Setting::put('classifier_mode', 'ai');

        $this->expectException(ClaudeException::class);
        $this->runJob($this->queuedItem('Looking for a 1BR in An Thuong, 6 months, 450$'));
    }

    public function test_draft_job_is_skipped_without_key(): void
    {
        config(['leadradar.ai.api_key' => '']);
        $lead = $this->makeLead();
        $this->app->instance(ClaudeClient::class, Mockery::mock(ClaudeClient::class)->shouldNotReceive('callTool')->getMock());

        (new DraftReplyJob($lead->id, force: true))->handle(app(ReplyDrafter::class));

        $this->assertSame(0, $lead->replyDrafts()->count());
    }

    // ------------------------------------------------------------ dashboard

    public function test_admin_sets_mode_on_settings_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(ManageSettings::class)
            ->assertSee('ANTHROPIC_API_KEY: chưa cấu hình')
            ->set('data.classifier_mode', 'keyword')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('keyword', Setting::get('classifier_mode'));
        $this->assertSame(ClassifierMode::KEYWORD, app(ClassifierMode::class)->mode());
    }

    public function test_prefilter_tester_shows_keyword_classification(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(PrefilterTester::class)
            ->set('data.text', 'Looking for a 1BR in An Thuong, 6 months, budget 450$')
            ->call('analyze')
            ->assertSee('Phân loại bằng keyword')
            ->assertSee(LeadIntent::TenantDemand->getLabel())
            ->assertSee('→ tạo lead')
            ->assertSee('Tìm căn 1 PN ở An Thượng');
    }

    public function test_stage_enum_values_are_valid(): void
    {
        foreach (self::intents() as [$text]) {
            $this->assertNotNull(LeadStage::tryFrom($this->rules($text)['stage']));
        }
    }
}
