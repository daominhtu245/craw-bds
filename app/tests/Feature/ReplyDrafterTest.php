<?php

namespace Tests\Feature;

use App\Jobs\DraftReplyJob;
use App\Models\Listing;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\ReplyDrafter;
use App\Services\Ai\ToolCallResult;
use App\Services\Site\SiteLinks;
use Database\Seeders\ListingSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\CreatesLeads;
use Tests\TestCase;

class ReplyDrafterTest extends TestCase
{
    use CreatesLeads;
    use RefreshDatabase;

    private function fakeDraft(?array &$captured = null): void
    {
        config(['leadradar.ai.api_key' => 'test-key']);
        $mock = Mockery::mock(ClaudeClient::class);
        $mock->shouldReceive('callTool')->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;

            return new ToolCallResult(
                input: [
                    'public_reply' => 'Hi! An Thuong is great for walking to the beach. We have Sea Breeze 1BR at 420$. Happy to do a video call. Call +84 905 123 456',
                    'dm' => 'Hello, thanks for your post. We can show you Sea Breeze 1BR by video call.',
                ],
                model: 'claude-sonnet-5-5',
                inputTokens: 1500,
                outputTokens: 300,
                stopReason: 'tool_use',
            );
        });
        $this->app->instance(ClaudeClient::class, $mock);
    }

    public function test_creates_two_variants_with_listings_and_rules(): void
    {
        $this->seed([SettingSeeder::class, ListingSeeder::class]);
        $lead = $this->makeLead();
        $listing = Listing::firstWhere('title', 'Sea Breeze 1BR');
        $lead->matchedListings()->attach($listing->id, ['match_score' => 85]);
        $captured = null;
        $this->fakeDraft($captured);

        $drafts = app(ReplyDrafter::class)->draft($lead);

        $this->assertSame(['public_reply', 'dm'], $drafts->pluck('variant')->all());
        $this->assertSame([$listing->id], $drafts->first()->listing_ids);
        $this->assertSame('claude-sonnet-5-5', $drafts->first()->model);
        // Phone numbers in the draft are masked as a safety net.
        $this->assertStringContainsString('[phone]', $drafts->first()->body);
        $this->assertSame(1, $lead->activities()->where('type', 'ai_draft')->count());

        [$model, $system, $user, $tool] = $captured;
        $this->assertSame('claude-sonnet-5-5', $model);
        $this->assertStringContainsString('YOUR AGENCY', $system);
        $this->assertStringContainsString('(en)', $system);
        $this->assertStringContainsString('No links in public replies', $user);
        $this->assertStringContainsString('Sea Breeze 1BR | area: an-thuong | 420 USD/month', $user);
        $this->assertSame('record_reply', $tool['name']);
    }

    public function test_site_link_goes_in_the_dm_only(): void
    {
        $this->seed([SettingSeeder::class, ListingSeeder::class]);
        $lead = $this->makeLead();
        $listing = Listing::firstWhere('title', 'Sea Breeze 1BR');   // public demo listing
        $lead->matchedListings()->attach($listing->id, ['match_score' => 85]);
        $link = app(SiteLinks::class)->forLead($lead, $listing, 'en');

        // The model forgets the link in the DM and puts it in the public reply.
        config(['leadradar.ai.api_key' => 'test-key']);
        $captured = null;
        $mock = Mockery::mock(ClaudeClient::class);
        $mock->shouldReceive('callTool')->andReturnUsing(function (...$args) use (&$captured, $link) {
            $captured = $args;

            return new ToolCallResult(
                input: ['public_reply' => "An Thuong is lovely. See {$link} for photos.", 'dm' => 'Hello! Happy to arrange a video call.'],
                model: 'claude-sonnet-5-5', inputTokens: 1, outputTokens: 1, stopReason: 'tool_use',
            );
        });
        $this->app->instance(ClaudeClient::class, $mock);

        $drafts = app(ReplyDrafter::class)->draft($lead);

        $this->assertStringContainsString($link, $captured[1]);   // given to the model (prompt v2)
        $this->assertStringContainsString('/en/apartments/'.$listing->slug.'?ref=', $link);
        $this->assertStringNotContainsString($link, $drafts->firstWhere('variant', 'public_reply')->body);
        $this->assertStringEndsWith($link, $drafts->firstWhere('variant', 'dm')->body);
    }

    public function test_job_skips_when_drafts_exist_unless_forced(): void
    {
        $this->seed(SettingSeeder::class);
        $lead = $this->makeLead();
        $this->fakeDraft();

        (new DraftReplyJob($lead->id))->handle(app(ReplyDrafter::class));
        (new DraftReplyJob($lead->id))->handle(app(ReplyDrafter::class));
        $this->assertSame(2, $lead->replyDrafts()->count());

        (new DraftReplyJob($lead->id, force: true))->handle(app(ReplyDrafter::class));
        $this->assertSame(4, $lead->replyDrafts()->count());
    }
}
