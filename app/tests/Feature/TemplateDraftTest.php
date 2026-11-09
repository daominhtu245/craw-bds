<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Jobs\DraftReplyJob;
use App\Models\Lead;
use App\Models\Setting;
use App\Services\Ai\AiBudget;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\ClaudeException;
use App\Services\Ai\ReplyDrafter;
use App\Services\Drafts\TemplateDrafter;
use App\Services\Matching\ListingMatcher;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

/**
 * Reply drafts from templates when the AI is not used.
 */
class TemplateDraftTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Setting::put('agency_name', 'Radar Homes');
        Setting::put('agency_website', 'https://radar.example.com');
        config(['leadradar.ai.api_key' => '']);
        $this->app->instance(ClaudeClient::class, Mockery::mock(ClaudeClient::class)->shouldNotReceive('callTool')->getMock());
    }

    private function matchedLead(array $attrs = []): Lead
    {
        $lead = $this->makeLead(array_merge(['arrival_date' => '2026-12-15'], $attrs));
        $matcher = app(ListingMatcher::class);
        $matcher->sync($lead, $matcher->match($lead));

        return $lead->fresh();
    }

    private function runJob(int $leadId, bool $force = false): void
    {
        (new DraftReplyJob($leadId, $force))->handle(app(ReplyDrafter::class));
    }

    public function test_renders_placeholders_and_optional_blocks(): void
    {
        $drafter = app(TemplateDrafter::class);
        $template = 'Hi from {agency}[[ in {area}]][[ under {budget}]]. Bye!';

        $this->assertSame('Hi from A in An Thuong under 450$. Bye!', $drafter->render($template, ['agency' => 'A', 'area' => 'An Thuong', 'budget' => '450$']));
        // Empty placeholder drops its block and leaves no double spaces before punctuation.
        $this->assertSame('Hi from A. Bye!', $drafter->render($template, ['agency' => 'A', 'area' => '', 'budget' => '']));
        $this->assertSame("Line 1\n\nLine 3", $drafter->render("Line 1\n[[{x}\n]]\n\n\nLine 3", []));
    }

    public function test_english_drafts_with_matched_listings(): void
    {
        $lead = $this->matchedLead();

        $this->runJob($lead->id);

        $public = $lead->replyDrafts()->where('variant', 'public_reply')->sole();
        $dm = $lead->replyDrafts()->where('variant', 'dm')->sole();

        $this->assertSame('template', $public->model);
        $this->assertSame('en', $public->language);
        $this->assertSame("Hi! Radar Homes has a few places in An Thuong within 450$/month that could fit what you're looking for. Happy to send photos and details by private message.", $public->body);
        $this->assertStringNotContainsString('http', $public->body);   // no links in public replies

        $this->assertStringContainsString('about a place in An Thuong', $dm->body);
        $this->assertStringContainsString('• Sea Breeze 1BR – 420$/month, An Thuong', $dm->body);
        $this->assertStringContainsString("You're arriving 15/12", $dm->body);
        $this->assertStringContainsString('More listings: https://radar.example.com', $dm->body);
        $this->assertNotEmpty($dm->listing_ids);
        $this->assertSame('Đã tạo bản nháp trả lời từ template.', $lead->activities()->latest('id')->first()->body);
    }

    public function test_blocks_disappear_when_lead_has_no_data(): void
    {
        $lead = $this->makeLead(['areas' => [], 'budget_max_usd' => null, 'arrival_date' => null]);

        $this->runJob($lead->id);

        $dm = $lead->replyDrafts()->where('variant', 'dm')->sole()->body;
        $this->assertStringNotContainsString('{', $dm);
        $this->assertStringNotContainsString('[[', $dm);
        $this->assertStringNotContainsString('arriving', $dm);
        $this->assertStringNotContainsString('options that match', $dm);   // no listings matched
        $this->assertStringStartsWith("Hi! I saw your post in the group. I'm with Radar Homes.", $dm);
    }

    public function test_russian_lead_gets_russian_template_and_unknown_language_falls_back_to_english(): void
    {
        $ru = $this->matchedLead(['language' => 'ru']);
        $ko = $this->makeLead(['language' => 'ko']);

        $this->runJob($ru->id);
        $this->runJob($ko->id);

        $this->assertStringStartsWith('Здравствуйте! У Radar Homes есть подходящие варианты в районе An Thuong', $ru->replyDrafts()->where('variant', 'public_reply')->sole()->body);
        $this->assertStringContainsString('420$/мес.', $ru->replyDrafts()->where('variant', 'dm')->sole()->body);
        $this->assertSame('en', $ko->replyDrafts()->first()->language);
    }

    public function test_edited_templates_from_settings_are_used(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(ManageSettings::class)
            ->assertSet('data.templates.en.public_reply', config('leadradar.drafts.templates.en.public_reply'))
            ->set('data.templates.en.public_reply', 'Hello! {agency} can help[[ around {area}]].')
            ->call('save')
            ->assertHasNoErrors();

        $lead = $this->makeLead();
        $this->runJob($lead->id);

        $this->assertSame('Hello! Radar Homes can help around An Thuong.', $lead->replyDrafts()->where('variant', 'public_reply')->sole()->body);
    }

    public function test_phone_numbers_typed_into_a_template_are_masked(): void
    {
        Setting::put('reply_templates', ['en' => ['public_reply' => 'Call +84 905 123 456', 'dm' => 'Hi']]);
        $lead = $this->makeLead();

        $this->runJob($lead->id);

        $this->assertSame('Call [phone]', $lead->replyDrafts()->where('variant', 'public_reply')->sole()->body);
    }

    public function test_engine_selection(): void
    {
        $lead = $this->makeLead();

        // keyword mode with a key: templates, AI never called (mock above).
        config(['leadradar.ai.api_key' => 'k']);
        Setting::put('classifier_mode', 'keyword');
        $this->runJob($lead->id);
        $this->assertSame('template', $lead->replyDrafts()->first()->model);

        // auto with spent budget: templates.
        Setting::put('classifier_mode', 'auto');
        config(['leadradar.ai.daily_budget_usd' => 0.5]);
        app(AiBudget::class)->record('claude-sonnet-5-5', 400_000, 30_000);
        $this->runJob($lead->id, force: true);
        $this->assertSame(4, $lead->replyDrafts()->where('model', 'template')->count());
    }

    public function test_auto_mode_falls_back_to_template_when_ai_fails(): void
    {
        config(['leadradar.ai.api_key' => 'k']);
        $mock = Mockery::mock(ClaudeClient::class);
        $mock->shouldReceive('callTool')->once()->andThrow(new ClaudeException('HTTP 500'));
        $this->app->instance(ClaudeClient::class, $mock);
        $lead = $this->makeLead();

        $this->runJob($lead->id);

        $this->assertSame(2, $lead->replyDrafts()->where('model', 'template')->count());
    }

    public function test_regenerate_button_creates_template_drafts_without_key(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->user(UserRole::Sales));
        $lead = $this->makeLead();

        Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('regenerateDrafts')
            ->assertHasNoActionErrors();

        $this->assertSame(2, $lead->replyDrafts()->where('model', 'template')->count());
    }
}
