<?php

namespace Tests\Feature;

use App\Enums\FilterReason;
use App\Enums\UserRole;
use App\Filament\Pages\ManageSettings;
use App\Models\Setting;
use App\Services\Classify\ClassifierMode;
use App\Services\Prefilter\Prefilter;
use App\Services\Settings\ConfigOverrides;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

/**
 * .env values moved to the Settings page (ConfigOverrides). Empty field = .env.
 */
class ConfigOverridesTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    private const KEY = 'sk-ant-api03-FakeKeyForTestsOnly_abcdefghijklmnop';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        ConfigOverrides::flush();
        ConfigOverrides::boot();
        $this->actingAs($this->user(UserRole::Admin));
    }

    public function test_values_saved_on_the_settings_page_override_env(): void
    {
        $this->assertSame(14, config('leadradar.prefilter.max_age_days'));

        Livewire::test(ManageSettings::class)
            ->assertSet('data.cfg.prefilter.max_age_days', null)
            ->set('data.cfg.prefilter.max_age_days', 30)
            ->set('data.cfg.ai.daily_budget_usd', 2.5)
            ->set('data.cfg.ai.model_classify', 'claude-sonnet-5-5')
            ->set('data.cfg.site.enabled', '0')
            ->set('data.cfg.leads.known_agent_threshold', 5)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(30, config('leadradar.prefilter.max_age_days'));
        $this->assertSame(2.5, config('leadradar.ai.daily_budget_usd'));
        $this->assertSame('claude-sonnet-5-5', config('leadradar.ai.model_classify'));
        $this->assertFalse(config('leadradar.site.enabled'));
        $this->assertSame(5, config('leadradar.leads.known_agent_threshold'));

        // The saved values show up again in the form.
        Livewire::test(ManageSettings::class)
            ->assertSet('data.cfg.prefilter.max_age_days', 30)
            ->assertSet('data.cfg.site.enabled', '0');

        // A fresh boot (next request / worker start) applies them too.
        config(['leadradar.prefilter.max_age_days' => 14]);
        ConfigOverrides::boot();
        $this->assertSame(30, config('leadradar.prefilter.max_age_days'));
    }

    public function test_clearing_a_field_goes_back_to_the_env_value(): void
    {
        ConfigOverrides::save(['prefilter.max_age_days' => 30]);
        $this->assertSame(30, config('leadradar.prefilter.max_age_days'));

        Livewire::test(ManageSettings::class)
            ->set('data.cfg.prefilter.max_age_days', null)
            ->call('save');

        $this->assertSame(14, config('leadradar.prefilter.max_age_days'));
        $this->assertArrayNotHasKey('prefilter.max_age_days', (array) Setting::get(ConfigOverrides::SETTING_KEY));
    }

    public function test_api_key_is_encrypted_never_shown_and_can_be_cleared(): void
    {
        $this->assertFalse(app(ClassifierMode::class)->hasApiKey());

        Livewire::test(ManageSettings::class)
            ->set('data.cfg.ai.api_key', self::KEY)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSet('data.cfg.ai.api_key', null);

        $stored = Setting::get(ConfigOverrides::SETTING_KEY)['ai.api_key'];
        $this->assertNotSame(self::KEY, $stored);
        $this->assertSame(self::KEY, Crypt::decryptString($stored));
        $this->assertSame(self::KEY, config('leadradar.ai.api_key'));
        $this->assertTrue(app(ClassifierMode::class)->hasApiKey());

        // Saving again with the field empty keeps the key.
        Livewire::test(ManageSettings::class)->assertSet('data.cfg.ai.api_key', null)->call('save');
        $this->assertSame(self::KEY, config('leadradar.ai.api_key'));

        // "Xóa key đã lưu" falls back to .env (empty in tests).
        Livewire::test(ManageSettings::class)->set('data.clear_api_key', true)->call('save');
        $this->assertEmpty(config('leadradar.ai.api_key'));
        $this->assertFalse(ConfigOverrides::hasStored('ai.api_key'));
    }

    public function test_invalid_values_are_rejected(): void
    {
        Livewire::test(ManageSettings::class)
            ->set('data.cfg.ai.api_key', 'not-a-key')
            ->set('data.cfg.ai.min_confidence', 3)
            ->set('data.cfg.prefilter.max_age_days', 0)
            ->call('save')
            ->assertHasFormErrors(['cfg.ai.api_key', 'cfg.ai.min_confidence', 'cfg.prefilter.max_age_days']);
    }

    public function test_overrides_change_behaviour(): void
    {
        $vietnamese = 'Mình cần thuê căn hộ 1 phòng ngủ ở Hải Châu, giá khoảng 8 triệu, ai có thì nhắn mình nhé.';
        $this->assertNull(app(Prefilter::class)->analyzeText($vietnamese)->reason);

        ConfigOverrides::save(['prefilter.accept_vi' => '0', 'site.enabled' => '0']);

        $this->assertSame(FilterReason::LangVi, app(Prefilter::class)->analyzeText($vietnamese)->reason);
        $this->get('/vi')->assertNotFound();
    }

    public function test_values_set_at_runtime_are_not_clobbered_by_reapplying(): void
    {
        config(['leadradar.ai.api_key' => 'runtime-key', 'leadradar.prefilter.max_age_days' => 99]);

        ConfigOverrides::apply();   // what a worker does before each job

        $this->assertSame('runtime-key', config('leadradar.ai.api_key'));
        $this->assertSame(99, config('leadradar.prefilter.max_age_days'));
    }

    public function test_placeholders_show_the_env_values(): void
    {
        Livewire::test(ManageSettings::class)
            ->assertSee('Theo .env: 14')
            ->assertSee('Theo .env (claude-haiku-5-5)')
            ->assertSee('Theo .env (Bật)');
    }
}
