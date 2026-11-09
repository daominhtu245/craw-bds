<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\PrefilterTester;
use App\Filament\Resources\Leads\Pages\EditLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Leads\RelationManagers\MatchedListingsRelationManager;
use App\Filament\Resources\Leads\RelationManagers\ReplyDraftsRelationManager;
use App\Models\KnownAgent;
use App\Models\Listing;
use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

/**
 * Phase 3 acceptance: a sale logs in, filters leads, claims one, sees matched
 * listings, copies a draft, changes status and sees the activity history.
 */
class DashboardTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_login_is_required(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
    }

    public function test_sales_can_open_lead_pages_but_not_admin_pages(): void
    {
        $lead = $this->makeLead();
        $this->actingAs($this->user());

        $this->get('/admin')->assertOk();
        $this->get('/admin/leads')->assertOk();
        $this->get("/admin/leads/{$lead->id}")->assertOk();
        $this->get('/admin/listings')->assertOk();

        foreach (['/admin/sources', '/admin/keywords', '/admin/areas', '/admin/raw-items', '/admin/users', '/admin/manage-settings', '/admin/prefilter-tester', '/horizon'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_admin_can_open_every_page(): void
    {
        $lead = $this->makeLead();
        $this->actingAs($this->user(UserRole::Admin));

        foreach (['/admin', '/admin/leads', "/admin/leads/{$lead->id}", "/admin/leads/{$lead->id}/edit", '/admin/listings', '/admin/sources',
            '/admin/keywords', '/admin/areas', '/admin/raw-items', "/admin/raw-items/{$lead->raw_item_id}", '/admin/users',
            '/admin/manage-settings', '/admin/prefilter-tester'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_sale_filters_and_claims_a_lead(): void
    {
        $sale = $this->user();
        $hot = $this->makeLead(['score' => 90, 'stage' => LeadStage::Urgent]);
        $cold = $this->makeLead(['score' => 30, 'areas' => ['son-tra'], 'stage' => LeadStage::Unknown]);
        $this->actingAs($sale);

        Livewire::test(ListLeads::class)
            ->assertCanSeeTableRecords([$hot, $cold], inOrder: true)
            ->filterTable('score_min', ['score_min' => 60])
            ->assertCanSeeTableRecords([$hot])
            ->assertCanNotSeeTableRecords([$cold])
            ->resetTableFilters()
            ->filterTable('areas', ['areas' => ['son-tra']])
            ->assertCanSeeTableRecords([$cold])
            ->assertCanNotSeeTableRecords([$hot])
            ->resetTableFilters()
            ->filterTable('stage', [LeadStage::Urgent->value])
            ->assertCanSeeTableRecords([$hot])
            ->assertCanNotSeeTableRecords([$cold])
            ->resetTableFilters()
            ->callTableAction('claim', $hot)
            ->filterTable('mine', true)
            ->assertCanSeeTableRecords([$hot])
            ->assertCanNotSeeTableRecords([$cold]);

        $this->assertSame($sale->id, $hot->fresh()->assigned_to);
        $this->assertSame(LeadStatus::Assigned, $hot->fresh()->status);
    }

    public function test_lead_page_shows_matches_drafts_and_timeline(): void
    {
        $lead = $this->makeLead();
        $listing = Listing::firstWhere('title', 'Sea Breeze 1BR');
        $lead->matchedListings()->attach($listing->id, ['match_score' => 85]);
        $draft = $lead->replyDrafts()->create(['variant' => 'public_reply', 'language' => 'en', 'body' => 'Hi there, we can help!', 'model' => 'claude-sonnet-5-5']);
        $this->actingAs($sale = $this->user());

        Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->assertSee($lead->summary_vi)
            ->assertSee('No links in public replies')
            ->assertSee($lead->rawItem->text);

        Livewire::test(MatchedListingsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$listing])
            ->assertSee('85');

        Livewire::test(ReplyDraftsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$draft])
            ->callTableAction('edit', $draft, ['body' => 'Edited reply'])
            ->assertHasNoTableActionErrors()
            ->callTableAction('markReplied', $draft);

        $this->assertSame('Edited reply', $draft->fresh()->body);
        $this->assertSame((string) $sale->id, $draft->fresh()->created_by);
        $this->assertSame(1, $lead->activities()->where('type', 'reply_sent')->count());
    }

    public function test_status_change_requires_note_for_lost_and_writes_timeline(): void
    {
        $lead = $this->makeLead();
        $this->actingAs($this->user());

        Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('changeStatus', ['status' => LeadStatus::Lost->value, 'note' => ''])
            ->assertHasActionErrors(['note' => 'required']);
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);

        Livewire::test(ViewLead::class, ['record' => $lead->getRouteKey()])
            ->callAction('changeStatus', ['status' => LeadStatus::Contacted->value, 'note' => ''])
            ->assertHasNoActionErrors()
            ->callAction('changeStatus', ['status' => LeadStatus::Lost->value, 'note' => 'Khách đã thuê chỗ khác'])
            ->assertHasNoActionErrors();

        $this->assertSame(LeadStatus::Lost, $lead->fresh()->status);

        Livewire::test(ActivitiesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertSee('Khách đã thuê chỗ khác')
            ->callTableAction('addNote', data: ['note' => 'Ghi chú thử'])
            ->assertSee('Ghi chú thử');
    }

    public function test_mark_agent_action(): void
    {
        $lead = $this->makeLead();
        $this->actingAs($this->user());

        Livewire::test(ListLeads::class)->callTableAction('markAgent', $lead);

        $this->assertTrue(KnownAgent::query()->where('author_key', $lead->rawItem->author_key)->exists());
        $this->assertSame(LeadStatus::Invalid, $lead->fresh()->status);
    }

    public function test_manual_edit_of_ai_fields_is_logged(): void
    {
        $lead = $this->makeLead();
        $this->actingAs($this->user());

        Livewire::test(EditLead::class, ['record' => $lead->getRouteKey()])
            ->fillForm(['budget_max_usd' => 600, 'areas' => ['my-khe']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(600, $lead->fresh()->budget_max_usd);
        $this->assertSame(['my-khe'], $lead->fresh()->areas);
        $this->assertStringContainsString('budget_max_usd', $lead->activities()->latest('id')->first()->body);
    }

    public function test_admin_saves_settings(): void
    {
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(ManageSettings::class)
            ->assertSet('data.fx.VND', 26000)
            ->set('data.notify_min_score', 70)
            ->set('data.fx.VND', 25500)
            ->set('data.quiet_hours', '22-06')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(70, Setting::get('notify_min_score'));
        $this->assertEquals(25500, Setting::get('fx_per_usd')['VND']);
        $this->assertSame('22-06', Setting::get('quiet_hours'));

        Livewire::test(ManageSettings::class)
            ->set('data.quiet_hours', 'night')
            ->call('save')
            ->assertHasFormErrors(['quiet_hours']);
    }

    public function test_prefilter_tester(): void
    {
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(PrefilterTester::class)
            ->set('data.text', 'Looking for a 1BR in An Thuong near My Khe for 6 months')
            ->call('analyze')
            ->assertSet('result.passed', true)
            ->assertSet('result.areas', ['an-thuong', 'my-khe'])
            ->set('data.text', 'Сдаю квартиру, свободна сейчас, цена: 10 млн, пишите в лс')
            ->call('analyze')
            ->assertSet('result.reason_code', 'negative_kw');
    }
}
