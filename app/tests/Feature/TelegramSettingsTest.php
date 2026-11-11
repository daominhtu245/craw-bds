<?php

namespace Tests\Feature;

use App\Enums\LeadStage;
use App\Enums\UserRole;
use App\Filament\Pages\ManageSettings;
use App\Jobs\NotifyLeadJob;
use App\Models\Setting;
use App\Services\Notify\QuietDigest;
use App\Services\Notify\QuietHours;
use App\Services\Notify\TelegramConfig;
use App\Services\Notify\TelegramNotifier;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

/**
 * Telegram destination set on the Settings page (bot token, chat id, on/off, test button).
 */
class TelegramSettingsTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    private const TOKEN = '123456789:AAFakeTokenForTestsOnly_abcdefghij';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['leadradar.notify.bot_token' => '', 'leadradar.notify.team_chat_id' => '']);
        Setting::put('quiet_hours', '');
    }

    private function notify(int $leadId): void
    {
        (new NotifyLeadJob($leadId))->handle(app(TelegramNotifier::class), app(QuietHours::class));
    }

    public function test_settings_page_saves_encrypted_token_and_chat_id(): void
    {
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(ManageSettings::class)
            ->assertSet('data.telegram_enabled', true)
            ->set('data.telegram_bot_token', self::TOKEN)
            ->set('data.telegram_chat_id', '@danang_sales')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.telegram_bot_token', null);

        $stored = Setting::get(TelegramConfig::BOT_TOKEN);
        $this->assertNotSame(self::TOKEN, $stored);
        $this->assertSame(self::TOKEN, Crypt::decryptString($stored));
        $this->assertSame('@danang_sales', Setting::get(TelegramConfig::CHAT_ID));

        // Saving again with an empty token field keeps the stored token.
        Livewire::test(ManageSettings::class)
            ->assertSet('data.telegram_bot_token', null)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(self::TOKEN, app(TelegramConfig::class)->botToken());
    }

    public function test_invalid_token_and_chat_id_are_rejected(): void
    {
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(ManageSettings::class)
            ->set('data.telegram_bot_token', 'not-a-token')
            ->set('data.telegram_chat_id', 'sales group')
            ->call('save')
            ->assertHasFormErrors(['telegram_bot_token', 'telegram_chat_id']);
    }

    public function test_lead_goes_to_the_chat_from_settings_over_env(): void
    {
        config(['leadradar.notify.bot_token' => '999:EnvToken', 'leadradar.notify.team_chat_id' => '-100111']);
        app(TelegramConfig::class)->saveToken(self::TOKEN);
        Setting::put(TelegramConfig::CHAT_ID, '-100222');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $lead = $this->makeLead();
        $this->notify($lead->id);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/bot'.self::TOKEN.'/sendMessage') && $r->data()['chat_id'] === '-100222');
        $this->assertNotNull($lead->fresh()->notified_at);
    }

    public function test_env_values_are_used_when_settings_are_empty(): void
    {
        config(['leadradar.notify.bot_token' => '999:EnvToken', 'leadradar.notify.team_chat_id' => '-100111']);

        $config = app(TelegramConfig::class);

        $this->assertSame('999:EnvToken', $config->botToken());
        $this->assertSame('-100111', $config->chatId());
        $this->assertTrue($config->isActive());
    }

    public function test_turned_off_sends_nothing_and_keeps_leads_for_later(): void
    {
        app(TelegramConfig::class)->saveToken(self::TOKEN);
        Setting::put(TelegramConfig::CHAT_ID, '-100222');
        Setting::put(TelegramConfig::ENABLED, false);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $lead = $this->makeLead();
        $this->notify($lead->id);
        $this->travel(10)->minutes();
        $this->assertSame(0, app(QuietDigest::class)->sendIfDue());

        Http::assertNothingSent();
        $this->assertNull($lead->fresh()->notified_at);

        // Switched back on: the digest picks the lead up.
        Setting::put(TelegramConfig::ENABLED, true);

        $this->assertSame(1, app(QuietDigest::class)->sendIfDue());
        $this->assertNotNull($lead->fresh()->notified_at);
    }

    public function test_empty_quiet_hours_means_always_notify_immediately(): void
    {
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(ManageSettings::class)
            ->set('data.quiet_hours', '')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('', Setting::get('quiet_hours'));
        $this->assertNull(app(QuietHours::class)->range());
        $this->assertFalse(app(QuietHours::class)->isQuiet(now()->setTimezone('Asia/Ho_Chi_Minh')->setTime(2, 0)));
    }

    public function test_send_test_uses_typed_values_before_saving(): void
    {
        $this->actingAs($this->user(UserRole::Admin));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        Livewire::test(ManageSettings::class)
            ->set('data.telegram_bot_token', self::TOKEN)
            ->set('data.telegram_chat_id', '-100333')
            ->call('sendTelegramTest')
            ->assertNotified('Đã gửi tin thử, kiểm tra chat Telegram');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/bot'.self::TOKEN.'/') && $r->data()['chat_id'] === '-100333');
        $this->assertNull(Setting::get(TelegramConfig::BOT_TOKEN));
    }

    public function test_send_test_failure_is_reported_without_the_token(): void
    {
        $this->actingAs($this->user(UserRole::Admin));
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

        Livewire::test(ManageSettings::class)
            ->set('data.telegram_bot_token', self::TOKEN)
            ->set('data.telegram_chat_id', '-100404')
            ->call('sendTelegramTest')
            ->assertNotified('Gửi thử thất bại');
    }

    public function test_send_test_without_credentials(): void
    {
        $this->actingAs($this->user(UserRole::Admin));
        Http::fake();

        Livewire::test(ManageSettings::class)
            ->call('sendTelegramTest')
            ->assertNotified('Chưa có bot token hoặc chat ID');

        Http::assertNothingSent();
    }

    public function test_urgent_lead_still_sent_during_quiet_hours_with_settings_chat(): void
    {
        app(TelegramConfig::class)->saveToken(self::TOKEN);
        Setting::put(TelegramConfig::CHAT_ID, '-100222');
        Setting::put('quiet_hours', '0-23');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $this->travelTo(now()->setTimezone('Asia/Ho_Chi_Minh')->setTime(3, 0));

        $lead = $this->makeLead(['stage' => LeadStage::Urgent]);
        $this->notify($lead->id);

        Http::assertSentCount(1);
    }
}
