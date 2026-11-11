<?php

namespace Tests\Feature;

use App\Enums\LeadIntent;
use App\Enums\PropertyType;
use App\Enums\UserRole;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Projects\Pages\ManageProjects;
use App\Jobs\NotifyInquiryJob;
use App\Jobs\NotifyLeadJob;
use App\Models\Inquiry;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\Project;
use App\Models\Setting;
use App\Services\Drafts\TemplateDrafter;
use App\Services\Notify\QuietHours;
use App\Services\Notify\TelegramConfig;
use App\Services\Notify\TelegramNotifier;
use App\Services\Privacy\DataRetention;
use App\Services\Site\LeadRef;
use App\Services\Site\TelegramWebApp;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

/**
 * App #2: public apartment site, inquiry form, Telegram Mini App (owner decision 2026-11-11).
 */
class SiteTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    private const BOT_TOKEN = '123456789:AAFakeTokenForTestsOnly_abcdefghij';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);   // includes 5 public demo listings
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Setting::put('agency_name', 'Radar Homes');
    }

    private function listing(): Listing
    {
        return Listing::query()->where('title', 'Sea Breeze 1BR')->firstOrFail();
    }

    /** A valid form, opened long enough ago. */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'listing_id' => $this->listing()->id,
            'name' => 'Test Customer',
            'contact_channel' => 'zalo',
            'contact' => '0905 123 456',
            'arrival_date' => now()->addDays(20)->toDateString(),
            'duration_months' => 6,
            'budget' => 12,
            'message' => 'Two people and a cat. Backup email test@example.com',
            'consent' => '1',
            'started' => Crypt::encryptString((string) (time() - 30)),
            'company' => '',
        ], $overrides);
    }

    private function signedInitData(array $user, ?int $authDate = null): string
    {
        $fields = ['auth_date' => (string) ($authDate ?? time()), 'query_id' => 'AAHtest', 'user' => json_encode($user)];
        ksort($fields);
        $check = collect($fields)->map(fn ($v, $k) => "{$k}={$v}")->implode("\n");
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true));

        return http_build_query($fields);
    }

    // ----------------------------------------------------------------- pages

    public function test_home_redirects_to_the_preferred_language(): void
    {
        $this->get('/', ['Accept-Language' => 'ru-RU,ru;q=0.9'])->assertRedirect('/ru');
        $this->get('/', ['Accept-Language' => 'en-US'])->assertRedirect('/en');
        $this->get('/?ref=abc', ['Accept-Language' => 'de-DE'])->assertRedirect('/vi?ref=abc');
    }

    public function test_listing_page_in_three_languages(): void
    {
        $this->get('/vi')->assertOk()->assertSee('Tìm căn hộ phù hợp tại Đà Nẵng')->assertSee('Căn 1 phòng ngủ gần biển An Thượng')->assertSee('triệu/tháng');
        $this->get('/en')->assertOk()->assertSee('Find your apartment in Da Nang')->assertSee('Sea Breeze 1BR near An Thuong beach')->assertSee('$420');
        $this->get('/ru')->assertOk()->assertSee('Найдите квартиру в Дананге')->assertSee('1-комнатная Sea Breeze');
        $this->get('/de')->assertNotFound();
    }

    public function test_only_published_listings_are_shown_and_filters_work(): void
    {
        $hidden = Listing::query()->where('title', 'Han River View 2BR')->firstOrFail();
        $hidden->update(['is_public' => false]);

        $this->get('/en')->assertOk()->assertDontSee('Han River View 2BR')->assertSee('4 apartments');
        $this->get('/en/apartments/'.$hidden->slug)->assertNotFound();

        $this->get('/en?bedrooms=3')->assertSee('Son Tra Family House 3BR')->assertDontSee('My Khe Cozy Studio');
        $this->get('/en?max_price=500')->assertSee('My Khe Cozy Studio')->assertDontSee('Marble Mountains Pool Villa');
        $this->get('/en?area=son-tra')->assertSee('1 apartment')->assertSee('Son Tra Family House 3BR')->assertDontSee('Sea Breeze');
    }

    public function test_detail_page_shows_texts_project_and_form(): void
    {
        $project = Project::create(['name' => 'Demo Tower', 'description' => ['en' => 'A self-written demo project.']]);
        $listing = $this->listing();
        $listing->update(['project_id' => $project->id, 'content' => array_merge($listing->content, ['ru' => []])]);

        $this->get('/en/apartments/'.$listing->slug)->assertOk()
            ->assertSee('Demo Tower')->assertSee('A self-written demo project.')
            ->assertSee('Interested in this apartment?')->assertSee('name="listing_id" value="'.$listing->id.'"', false);

        // Missing Russian text falls back to English.
        $this->get('/ru/apartments/'.$listing->slug)->assertOk()->assertSee('Sea Breeze 1BR near An Thuong beach');
    }

    public function test_site_can_be_switched_off(): void
    {
        config(['leadradar.site.enabled' => false]);

        $this->get('/vi')->assertNotFound();
        $this->get('/')->assertNotFound();
    }

    // --------------------------------------------------------------- inquiry

    public function test_inquiry_creates_encrypted_inquiry_and_hot_lead(): void
    {
        Queue::fake();

        $this->from('/en/apartments/'.$this->listing()->slug)
            ->post('/en/inquiry', $this->form())
            ->assertRedirect('/en/apartments/'.$this->listing()->slug)
            ->assertSessionHas('inquiry_sent');

        $inquiry = Inquiry::sole();
        $this->assertSame('0905 123 456', $inquiry->contact);
        $this->assertSame('Test Customer', $inquiry->name);
        $this->assertSame('website', $inquiry->source);
        $this->assertSame(12, $inquiry->budget_usd);   // English form: USD
        $this->assertStringNotContainsString('test@example.com', $inquiry->message);

        // Encrypted at rest.
        $raw = DB::table('inquiries')->where('id', $inquiry->id)->first();
        $this->assertStringNotContainsString('0905', $raw->contact);
        $this->assertStringNotContainsString('Test Customer', $raw->name);

        $lead = $inquiry->lead;
        $this->assertSame(LeadIntent::TenantDemand, $lead->intent);
        $this->assertSame(100, $lead->score);
        $this->assertSame('en', $lead->language);
        $this->assertSame(['an-thuong'], $lead->areas);
        $this->assertSame(PropertyType::OneBr, $lead->property_type);
        $this->assertSame('website', $lead->rawItem->source->type->value);
        $this->assertStringNotContainsString('0905', $lead->rawItem->text);
        $this->assertStringContainsString('Khách gửi yêu cầu qua website', $lead->summary_vi);

        Queue::assertPushed(NotifyInquiryJob::class, fn ($job) => $job->inquiryId === $inquiry->id);
        Queue::assertNotPushed(NotifyLeadJob::class);
    }

    public function test_vietnamese_budget_is_million_vnd(): void
    {
        Queue::fake();

        $this->post('/vi/inquiry', $this->form(['budget' => 13]))->assertSessionHasNoErrors();

        $this->assertSame(500, Inquiry::sole()->budget_usd);   // 13,000,000 / 26,000
    }

    public function test_telegram_alert_includes_the_contact_and_is_sent_once(): void
    {
        Queue::fake();
        app(TelegramConfig::class)->saveToken(self::BOT_TOKEN);
        Setting::put(TelegramConfig::CHAT_ID, '-100222');
        Setting::put('quiet_hours', '0-23');   // inquiries ignore quiet hours
        $this->post('/en/inquiry', $this->form());
        $inquiry = Inquiry::sole();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        (new NotifyInquiryJob($inquiry->id))->handle(app(TelegramNotifier::class));
        (new NotifyLeadJob($inquiry->lead_id))->handle(app(TelegramNotifier::class), app(QuietHours::class));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            $text = $r->data()['text'];

            return str_contains($text, 'Khách gửi yêu cầu') && str_contains($text, '0905 123 456')
                && str_contains($text, 'Test Customer') && str_contains($text, 'Sea Breeze 1BR');
        });
        $this->assertNotNull($inquiry->lead->fresh()->notified_at);
    }

    public function test_ref_from_a_reply_draft_attaches_to_the_existing_lead(): void
    {
        Queue::fake();
        $lead = $this->makeLead();
        $leads = Lead::count();

        $this->get('/en?ref='.LeadRef::encode($lead->id))->assertOk();
        $this->post('/en/inquiry', $this->form(['ref' => '']))->assertSessionHasNoErrors();

        $this->assertSame($leads, Lead::count());
        $this->assertSame($lead->id, Inquiry::sole()->lead_id);
        $this->assertStringContainsString('Khách gửi yêu cầu qua website', $lead->activities()->latest('id')->first()->body);
    }

    public function test_tampered_ref_creates_a_new_lead(): void
    {
        Queue::fake();
        $lead = $this->makeLead();

        $this->post('/en/inquiry', $this->form(['ref' => base_convert((string) $lead->id, 10, 36).'-000000']));

        $this->assertNotSame($lead->id, Inquiry::sole()->lead_id);
        $this->assertNull(LeadRef::decode('zz-123456'));
        $this->assertSame($lead->id, LeadRef::decode(LeadRef::encode($lead->id)));
    }

    public function test_bots_and_missing_consent_are_rejected(): void
    {
        Queue::fake();

        $this->post('/en/inquiry', $this->form(['company' => 'Spam Inc']))->assertSessionHasErrors('company');
        $this->post('/en/inquiry', $this->form(['started' => Crypt::encryptString((string) time())]))->assertSessionHasErrors('started');
        $this->post('/en/inquiry', $this->form(['started' => 'forged']))->assertSessionHasErrors('started');
        $this->post('/en/inquiry', $this->form(['consent' => null]))->assertSessionHasErrors('consent');
        $this->post('/vi/inquiry', $this->form(['contact' => '']))->assertSessionHasErrors(['contact' => 'Vui lòng nhập Số điện thoại / tài khoản.']);

        $this->assertSame(0, Inquiry::count());
    }

    public function test_inquiries_are_rate_limited(): void
    {
        Queue::fake();
        config(['leadradar.site.inquiry_rate_limit_per_hour' => 2]);

        $this->post('/en/inquiry', $this->form())->assertRedirect();
        $this->post('/en/inquiry', $this->form())->assertRedirect();
        $this->post('/en/inquiry', $this->form())->assertTooManyRequests();
    }

    // ------------------------------------------------------------- mini app

    public function test_mini_app_user_can_use_their_telegram_username(): void
    {
        Queue::fake();
        app(TelegramConfig::class)->saveToken(self::BOT_TOKEN);

        $this->post('/ru/inquiry', $this->form([
            'contact_channel' => 'telegram_self',
            'contact' => '',
            'tg_init_data' => $this->signedInitData(['id' => 999, 'first_name' => 'Test', 'username' => 'test_tenant']),
        ]))->assertSessionHasNoErrors();

        $inquiry = Inquiry::sole();
        $this->assertSame('telegram_miniapp', $inquiry->source);
        $this->assertSame('telegram', $inquiry->contact_channel);
        $this->assertSame('@test_tenant', $inquiry->contact);
        $this->assertSame('telegram_miniapp', $inquiry->lead->rawItem->source->identifier);
    }

    public function test_forged_or_old_init_data_is_not_trusted(): void
    {
        Queue::fake();
        app(TelegramConfig::class)->saveToken(self::BOT_TOKEN);
        $webApp = app(TelegramWebApp::class);
        $valid = $this->signedInitData(['id' => 1, 'username' => 'someone']);

        $this->assertSame('someone', $webApp->validate($valid)['username']);
        $this->assertNull($webApp->validate(str_replace('someone', 'attacker', $valid)));
        $this->assertNull($webApp->validate($this->signedInitData(['id' => 1], time() - 2 * 86400)));
        $this->assertNull($webApp->validate(''));

        // "My Telegram" without valid initData and without a typed contact is refused.
        $this->post('/en/inquiry', $this->form(['contact_channel' => 'telegram_self', 'contact' => '', 'tg_init_data' => 'hash=bad&auth_date=1']))
            ->assertSessionHasErrors('contact');
        $this->assertSame(0, Inquiry::count());
    }

    // ------------------------------------------------------- dashboard & privacy

    public function test_sales_see_the_contact_on_the_lead_page(): void
    {
        Queue::fake();
        $this->post('/en/inquiry', $this->form());
        $this->actingAs($this->user(UserRole::Sales));

        Livewire::test(ViewLead::class, ['record' => Inquiry::sole()->lead_id])
            ->assertSee('Khách gửi yêu cầu (website / Mini App)')
            ->assertSee('Zalo: 0905 123 456')
            ->assertSee('Test Customer');
    }

    public function test_contacts_are_erased_after_the_retention_period(): void
    {
        Queue::fake();
        $this->post('/en/inquiry', $this->form());
        $this->travel(91)->days();

        $this->assertSame(1, app(DataRetention::class)->purgeInquiryContacts());

        $inquiry = Inquiry::sole();
        $this->assertNull($inquiry->contact);
        $this->assertNull($inquiry->name);
        $this->assertNotNull($inquiry->contact_purged_at);
        $this->assertSame(0, app(DataRetention::class)->purgeInquiryContacts());
    }

    public function test_reply_draft_links_to_the_site_with_a_tracking_code(): void
    {
        $lead = $this->makeLead();
        app(\App\Services\Matching\ListingMatcher::class)->sync($lead, app(\App\Services\Matching\ListingMatcher::class)->match($lead));

        $dm = app(TemplateDrafter::class)->draft($lead->fresh())->firstWhere('variant', 'dm')->body;

        $this->assertMatchesRegularExpression('~/en/apartments/[a-z0-9-]+\?ref=([0-9a-z]+-[0-9a-f]{6})~', $dm);
        preg_match('~ref=([0-9a-z]+-[0-9a-f]{6})~', $dm, $m);
        $this->assertSame($lead->id, LeadRef::decode($m[1]));
    }

    public function test_admin_manages_projects(): void
    {
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(ManageProjects::class)
            ->callAction('create', ['name' => 'Demo Tower', 'description' => ['vi' => 'Mô tả demo']])
            ->assertHasNoActionErrors();

        $this->assertSame('demo-tower', Project::sole()->slug);
    }
}
