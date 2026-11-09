<?php

namespace Tests\Feature;

use App\Jobs\DraftReplyJob;
use App\Models\Listing;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\ReplyDrafter;
use App\Services\Ai\ToolCallResult;
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
