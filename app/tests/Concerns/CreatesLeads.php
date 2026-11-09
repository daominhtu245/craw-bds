<?php

namespace Tests\Concerns;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\PropertyType;
use App\Enums\RawItemStatus;
use App\Enums\UserRole;
use App\Models\Lead;
use App\Models\RawItem;
use App\Models\Source;
use App\Models\User;

trait CreatesLeads
{
    protected function user(UserRole $role = UserRole::Sales, array $attrs = []): User
    {
        return User::factory()->create(array_merge(['role' => $role], $attrs));
    }

    protected function makeLead(array $attrs = [], array $itemAttrs = []): Lead
    {
        static $seq = 0;
        $seq++;

        $source = Source::query()->firstOrCreate(
            ['type' => 'telegram', 'identifier' => 'test_group'],
            ['name' => 'Test Group', 'rules_note' => 'No links in public replies'],
        );

        $item = RawItem::create(array_merge([
            'source_id' => $source->id,
            'external_id' => "lead:{$seq}",
            'url' => "https://t.me/test_group/{$seq}",
            'author_handle' => "author{$seq}",
            'author_key' => hash('sha256', "telegram:{$seq}"),
            'text' => "Looking for a 1BR in An Thuong, sample #{$seq}",
            'content_hash' => hash('sha256', "lead{$seq}"),
            'posted_at' => now()->subHours(2),
            'status' => RawItemStatus::Classified,
            'ai_model' => 'claude-haiku-5-5',
            'ai_input_tokens' => 1000,
            'ai_output_tokens' => 200,
        ], $itemAttrs));

        return Lead::create(array_merge([
            'raw_item_id' => $item->id,
            'intent' => LeadIntent::TenantDemand,
            'stage' => LeadStage::PreArrival,
            'language' => 'en',
            'summary_vi' => "Khách cần căn 1PN ở An Thượng #{$seq}",
            'property_type' => PropertyType::OneBr,
            'bedrooms_min' => 1,
            'areas' => ['an-thuong'],
            'budget_max_usd' => 450,
            'duration_months' => 6,
            'requirements' => [],
            'confidence' => 0.9,
            'score' => 70,
            'status' => LeadStatus::New,
            'expires_at' => now()->addDays(60),
        ], $attrs));
    }
}
