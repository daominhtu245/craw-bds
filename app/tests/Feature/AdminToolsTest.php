<?php

namespace Tests\Feature;

use App\Enums\FilterReason;
use App\Enums\RawItemStatus;
use App\Enums\UserRole;
use App\Filament\Resources\CollectorHeartbeats\Pages\ListCollectorHeartbeats;
use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\KnownAgents\Pages\ListKnownAgents;
use App\Jobs\PrefilterItemJob;
use App\Models\CollectorHeartbeat;
use App\Models\Inquiry;
use App\Models\KnownAgent;
use App\Models\RawItem;
use Database\Seeders\DatabaseSeeder;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

/**
 * Known agents, inquiries list, collector health and the profile page.
 */
class AdminToolsTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // ------------------------------------------------------------ known agents

    public function test_known_agents_show_context_and_can_be_removed_with_requeue(): void
    {
        Queue::fake();
        $this->actingAs($admin = $this->user(UserRole::Admin));
        $lead = $this->makeLead([], ['author_handle' => 'wrongly_marked', 'text' => 'Looking for a 2BR, sample text']);
        $key = $lead->rawItem->author_key;
        $agent = KnownAgent::create(['author_key' => $key, 'reason' => "manual: lead #{$lead->id}", 'created_by' => (string) $admin->id]);
        $filtered = RawItem::create([
            'source_id' => $lead->rawItem->source_id, 'external_id' => 'x:filtered', 'url' => 'https://t.me/test_group/999',
            'author_key' => $key, 'text' => 'Another post by the same author', 'content_hash' => hash('sha256', 'filtered'),
            'posted_at' => now()->subHour(), 'status' => RawItemStatus::FilteredOut, 'filter_reason' => FilterReason::KnownAgent,
        ]);

        Livewire::test(ListKnownAgents::class)
            ->assertSee('wrongly_marked')
            ->assertSee('Sale đánh dấu')
            ->assertSee($admin->name)
            ->callTableAction('remove', $agent, ['requeue' => true])
            ->assertNotified('Đã gỡ khỏi danh sách');

        $this->assertSame(0, KnownAgent::count());
        $this->assertSame(RawItemStatus::New, $filtered->fresh()->status);
        $this->assertNull($filtered->fresh()->filter_reason);
        Queue::assertPushed(PrefilterItemJob::class, fn ($job) => $job->rawItemId === $filtered->id);
    }

    public function test_removing_without_requeue_leaves_posts_filtered(): void
    {
        Queue::fake();
        $this->actingAs($this->user(UserRole::Admin));
        $lead = $this->makeLead();
        $agent = KnownAgent::create(['author_key' => $lead->rawItem->author_key, 'reason' => 'auto: 3 agent/landlord posts', 'created_by' => 'system']);

        Livewire::test(ListKnownAgents::class)
            ->assertSee('Tự động')
            ->assertSee('Hệ thống (tự động)')
            ->callTableAction('remove', $agent, ['requeue' => false]);

        $this->assertSame(0, KnownAgent::count());
        Queue::assertNothingPushed();
    }

    // --------------------------------------------------------------- inquiries

    public function test_sales_see_inquiries_with_contacts(): void
    {
        $lead = $this->makeLead();
        Inquiry::create([
            'lead_id' => $lead->id, 'source' => 'telegram_miniapp', 'locale' => 'ru', 'name' => 'Test Customer',
            'contact_channel' => 'telegram', 'contact' => '@test_tenant', 'duration_months' => 6, 'budget_usd' => 500,
            'ip_hash' => str_repeat('a', 64), 'consent_at' => now(),
        ]);
        Inquiry::create([
            'source' => 'website', 'locale' => 'vi', 'name' => null, 'contact_channel' => 'zalo', 'contact' => null,
            'ip_hash' => str_repeat('b', 64), 'consent_at' => now()->subDays(100), 'contact_purged_at' => now(),
        ]);
        $this->actingAs($this->user(UserRole::Sales));

        Livewire::test(ListInquiries::class)
            ->assertSee('Test Customer')
            ->assertSee('Telegram: @test_tenant')
            ->assertSee('Mini App')
            ->assertSee('6 tháng · ≤ 500$')
            ->assertSee('Đã xóa theo hạn lưu');

        $this->get('/admin/inquiries')->assertOk();
    }

    // ---------------------------------------------------------------- collectors

    public function test_collector_health_matches_the_alert_rule(): void
    {
        $this->actingAs($this->user(UserRole::Admin));
        CollectorHeartbeat::create(['collector' => 'telegram', 'ok' => true, 'last_seen_at' => now()->subMinutes(5), 'meta' => ['sources' => 3]]);
        CollectorHeartbeat::create(['collector' => 'facebook', 'ok' => false, 'last_seen_at' => now()->subMinutes(5), 'meta' => ['error' => 'not logged in']]);
        CollectorHeartbeat::create(['collector' => 'forum', 'ok' => true, 'last_seen_at' => now()->subMinutes(45)]);

        Livewire::test(ListCollectorHeartbeats::class)
            ->assertSee('Hoạt động')
            ->assertSee('Báo lỗi')
            ->assertSee('error: not logged in')
            ->assertSee('Im lặng');

        $this->actingAs($this->user(UserRole::Sales))->get('/admin/collector-heartbeats')->assertForbidden();
    }

    // ------------------------------------------------------------------ profile

    public function test_sales_can_change_their_own_password(): void
    {
        $sales = $this->user(UserRole::Sales, ['password' => Hash::make('old-password-123')]);
        $this->actingAs($sales);

        $this->get('/admin/profile')->assertOk();

        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'new-password-456',
                'passwordConfirmation' => 'new-password-456',
                'currentPassword' => 'old-password-123',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('new-password-456', $sales->fresh()->password));
    }
}
