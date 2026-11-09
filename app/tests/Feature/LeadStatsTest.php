<?php

namespace Tests\Feature;

use App\Enums\FilterReason;
use App\Enums\LeadStatus;
use App\Enums\RawItemStatus;
use App\Enums\UserRole;
use App\Filament\Widgets\AiCostToday;
use App\Filament\Widgets\LeadFunnelChart;
use App\Filament\Widgets\LeadsBreakdownChart;
use App\Filament\Widgets\LeadStatsOverview;
use App\Filament\Widgets\PrefilterReasonsChart;
use App\Models\RawItem;
use App\Services\Leads\LeadStats;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

class LeadStatsTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    public function test_aggregates(): void
    {
        $this->makeLead(['status' => LeadStatus::Won, 'language' => 'ru']);
        $this->makeLead(['status' => LeadStatus::Contacted]);
        $this->makeLead(['status' => LeadStatus::Invalid]);
        $filtered = $this->makeLead()->rawItem;
        $filtered->lead()->delete();
        $filtered->forceFill(['status' => RawItemStatus::FilteredOut, 'filter_reason' => FilterReason::NegativeKw, 'ai_model' => null])->save();

        $stats = app(LeadStats::class);
        $since = now()->subDay();

        $this->assertSame(['collected' => 4, 'passed' => 3, 'leads' => 3, 'contacted' => 2, 'won' => 1], $stats->funnel($since));
        $this->assertEqualsWithDelta(1 / 3, $stats->invalidRate(30), 0.001);
        $this->assertSame(['en' => 2, 'ru' => 1], $stats->leadsByLanguage($since));
        $this->assertSame(['telegram' => 3], $stats->leadsBySourceType($since));
        $this->assertSame(['passed' => 3, 'negative_kw' => 1], $stats->filterReasons($since));
        $this->assertEqualsWithDelta(0.25, $stats->prefilterRate($since), 0.001);
        // 3 items × (1000 in × $0.10 + 200 out × $0.50) / 1M
        $this->assertEqualsWithDelta(3 * (1000 * 0.10 + 200 * 0.50) / 1_000_000, $stats->aiCostSince($since), 1e-9);
        $this->assertSame(3, RawItem::query()->whereNotNull('ai_model')->count());
    }

    public function test_widgets_render(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->makeLead();
        $this->actingAs($this->user(UserRole::Admin));

        Livewire::test(LeadStatsOverview::class)->assertSee('Lead mới hôm nay');
        Livewire::test(AiCostToday::class)->assertSee('Chi phí AI hôm nay');
        Livewire::test(LeadFunnelChart::class)->assertOk();
        Livewire::test(LeadsBreakdownChart::class)->assertOk()->set('filter', 'language')->assertOk();
        Livewire::test(PrefilterReasonsChart::class)->assertOk();
    }
}
