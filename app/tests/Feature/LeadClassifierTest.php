<?php

namespace Tests\Feature;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\PropertyType;
use App\Enums\RawItemStatus;
use App\Models\KnownAgent;
use App\Models\Lead;
use App\Models\RawItem;
use App\Models\Source;
use App\Services\Ai\LeadClassifier;
use Database\Seeders\AreaSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesClaude;
use Tests\TestCase;

class LeadClassifierTest extends TestCase
{
    use FakesClaude;
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([AreaSeeder::class, SettingSeeder::class]);
        $this->source = Source::create(['type' => 'telegram', 'name' => 'Da Nang Forum', 'identifier' => 'dn']);
    }

    private function item(array $attrs = []): RawItem
    {
        static $seq = 0;
        $seq++;

        return RawItem::create(array_merge([
            'source_id' => $this->source->id,
            'external_id' => "c:{$seq}",
            'url' => "https://t.me/dn/{$seq}",
            'author_key' => hash('sha256', 'telegram:author'),
            'text' => 'Looking for a 1BR in An Thuong from December, 6 months, budget 450$, we have a cat',
            'content_hash' => hash('sha256', (string) $seq),
            'posted_at' => '2026-11-09 03:00:00',
            'status' => RawItemStatus::Queued,
        ], $attrs));
    }

    public function test_tenant_demand_creates_structured_lead(): void
    {
        $this->fakeClaude(['tenant_demand']);
        $item = $this->item();

        $lead = app(LeadClassifier::class)->classify($item);

        $this->assertInstanceOf(Lead::class, $lead);
        $this->assertSame(LeadIntent::TenantDemand, $lead->intent);
        $this->assertSame(LeadStage::PreArrival, $lead->stage);
        $this->assertSame(PropertyType::OneBr, $lead->property_type);
        $this->assertSame(['an-thuong'], $lead->areas);
        $this->assertSame(450, $lead->budget_max_usd);
        $this->assertNull($lead->budget_min_usd);
        $this->assertSame('450$', $lead->budget_raw);
        // Unknown tag "jacuzzi" dropped.
        $this->assertSame(['pet_friendly', 'kitchen'], $lead->requirements);
        $this->assertSame('2026-12-01', $lead->arrival_date->toDateString());
        $this->assertSame('2027-01-08', $lead->expires_at->toDateString());
        $this->assertSame(1, $lead->activities()->count());

        $item->refresh();
        $this->assertSame('tenant_demand', $item->classification['intent']);
        $this->assertSame('claude-haiku-5-5', $item->ai_model);
        $this->assertSame(900, $item->ai_input_tokens);
    }

    public function test_vnd_budget_is_converted_and_unknown_area_ignored(): void
    {
        $this->fakeClaude(['tenant_demand_ru_vnd']);

        $lead = app(LeadClassifier::class)->classify($this->item());

        $this->assertSame(500, $lead->budget_min_usd);
        $this->assertSame(700, $lead->budget_max_usd);
        $this->assertSame(['my-khe'], $lead->areas);
        $this->assertSame('ru', $lead->language);
    }

    public function test_low_confidence_escalates_to_second_model(): void
    {
        $this->fakeClaude(['low_confidence', $this->toolResult('tenant_demand', 'claude-sonnet-5-5')]);

        $lead = app(LeadClassifier::class)->classify($item = $this->item());

        $this->assertNotNull($lead);
        $this->assertSame('claude-sonnet-5-5', $item->fresh()->ai_model);
        $this->assertSame(1800, $item->fresh()->ai_input_tokens);
    }

    public function test_still_low_confidence_after_escalation_creates_no_lead(): void
    {
        $this->fakeClaude(['low_confidence', 'low_confidence']);

        $this->assertNull(app(LeadClassifier::class)->classify($this->item()));
        $this->assertSame(0, Lead::count());
    }

    public function test_non_lead_intent_creates_no_lead(): void
    {
        $this->fakeClaude(['landlord_offer']);
        $item = $this->item();

        $this->assertNull(app(LeadClassifier::class)->classify($item));
        $this->assertSame('landlord_offer', $item->fresh()->classification['intent']);
        $this->assertSame(0, Lead::count());
    }

    public function test_author_with_three_agent_posts_becomes_known_agent(): void
    {
        $this->fakeClaude(['landlord_offer', 'landlord_offer', 'landlord_offer']);
        $classifier = app(LeadClassifier::class);

        $classifier->classify($this->item());
        $classifier->classify($this->item());
        $this->assertSame(0, KnownAgent::count());

        $classifier->classify($this->item());
        $this->assertSame(1, KnownAgent::count());
        $this->assertSame(hash('sha256', 'telegram:author'), KnownAgent::first()->author_key);
    }

    public function test_rerun_does_not_duplicate_lead(): void
    {
        $this->fakeClaude(['tenant_demand', 'tenant_demand']);
        $item = $this->item();

        $first = app(LeadClassifier::class)->classify($item);
        $second = app(LeadClassifier::class)->classify($item);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, $first->activities()->count());
    }

    public function test_out_of_range_values_are_sanitized(): void
    {
        $bad = $this->fixture('tenant_demand');
        $bad['confidence'] = 7;
        $bad['duration_months'] = 999;
        $bad['arrival_date'] = '2026-02-31';
        $bad['summary_vi'] = str_repeat('x', 900);
        $this->fakeClaude([$bad]);

        $lead = app(LeadClassifier::class)->classify($this->item());

        $this->assertSame('1.00', (string) $lead->confidence);
        $this->assertNull($lead->duration_months);
        $this->assertNull($lead->arrival_date);
        $this->assertSame(500, mb_strlen($lead->summary_vi));
    }
}
