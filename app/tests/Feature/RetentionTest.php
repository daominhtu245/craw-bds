<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Enums\RawItemStatus;
use App\Models\LeadActivity;
use App\Models\RawItem;
use App\Models\ReplyDraft;
use App\Services\Privacy\DataRetention;
use Database\Seeders\ListingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

/**
 * SPEC 15 retention rules (Phase 6 acceptance: "test retention xanh").
 */
class RetentionTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    private function rawItem(RawItemStatus $status, int $daysOld): RawItem
    {
        static $seq = 0;
        $seq++;
        $lead = $this->makeLead([], ['status' => $status, 'external_id' => "ret:{$seq}"]);
        $item = $lead->rawItem;
        $lead->delete();
        $item->forceFill(['created_at' => now()->subDays($daysOld)])->save();

        return $item;
    }

    public function test_raw_items_without_lead_are_deleted_after_14_days(): void
    {
        $oldFiltered = $this->rawItem(RawItemStatus::FilteredOut, 15);
        $oldClassified = $this->rawItem(RawItemStatus::Classified, 20);
        $recentFiltered = $this->rawItem(RawItemStatus::FilteredOut, 13);
        $oldQueued = $this->rawItem(RawItemStatus::Queued, 30);   // still in the pipeline: keep
        $oldError = $this->rawItem(RawItemStatus::Error, 30);     // needs a look: keep
        $withLead = $this->makeLead()->rawItem;
        $withLead->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->assertSame(2, app(DataRetention::class)->purgeRawItems());

        $this->assertModelMissing($oldFiltered);
        $this->assertModelMissing($oldClassified);
        $this->assertModelExists($recentFiltered);
        $this->assertModelExists($oldQueued);
        $this->assertModelExists($oldError);
        $this->assertModelExists($withLead);
    }

    public function test_expired_open_leads_are_deleted_with_everything_attached(): void
    {
        $this->seed(ListingSeeder::class);
        $expired = [];
        foreach ([LeadStatus::New, LeadStatus::Assigned, LeadStatus::Lost, LeadStatus::Invalid] as $status) {
            $lead = $this->makeLead(['status' => $status, 'expires_at' => now()->subDay()]);
            $lead->replyDrafts()->create(['variant' => 'dm', 'language' => 'en', 'body' => 'x']);
            $lead->activities()->create(['type' => 'note', 'body' => 'x']);
            $lead->matchedListings()->attach(1, ['match_score' => 80]);
            $expired[] = $lead;
        }
        $notExpired = $this->makeLead(['status' => LeadStatus::New, 'expires_at' => now()->addDay()]);
        $contacted = $this->makeLead(['status' => LeadStatus::Contacted, 'expires_at' => now()->subDay()]);
        $won = $this->makeLead(['status' => LeadStatus::Won, 'expires_at' => now()->subDay()]);

        $this->assertSame(4, app(DataRetention::class)->purgeExpiredLeads());

        foreach ($expired as $lead) {
            $this->assertModelMissing($lead);
            $this->assertNull(RawItem::find($lead->raw_item_id));
        }
        $this->assertSame(0, ReplyDraft::count());
        $this->assertSame(0, LeadActivity::count());
        $this->assertDatabaseCount('lead_listing_matches', 0);
        $this->assertModelExists($notExpired);
        $this->assertModelExists($contacted);
        $this->assertModelExists($won);
    }

    public function test_won_leads_are_kept_but_anonymised_after_180_days(): void
    {
        $oldWon = $this->makeLead(['status' => LeadStatus::Won], ['posted_at' => now()->subDays(181), 'context_text' => 'parent post']);
        $recentWon = $this->makeLead(['status' => LeadStatus::Won], ['posted_at' => now()->subDays(100)]);

        $this->assertSame(1, app(DataRetention::class)->anonymiseWonLeads());
        // Idempotent.
        $this->assertSame(0, app(DataRetention::class)->anonymiseWonLeads());

        $item = $oldWon->rawItem->fresh();
        $this->assertSame(DataRetention::ANONYMISED_TEXT, $item->text);
        $this->assertNull($item->context_text);
        $this->assertNull($item->author_handle);
        $this->assertModelExists($oldWon);
        $this->assertNotSame(DataRetention::ANONYMISED_TEXT, $recentWon->rawItem->fresh()->text);
    }

    public function test_command_and_schedule(): void
    {
        $this->artisan('leadradar:retention')->assertSuccessful();

        $this->artisan('schedule:list')
            ->expectsOutputToContain('leadradar:retention')
            ->expectsOutputToContain('leadradar:health')
            ->expectsOutputToContain('leadradar:quiet-digest');
    }
}
