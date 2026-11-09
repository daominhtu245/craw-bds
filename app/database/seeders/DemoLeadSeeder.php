<?php

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\LeadStatus;
use App\Enums\PropertyType;
use App\Enums\RawItemStatus;
use App\Models\Lead;
use App\Models\Listing;
use App\Models\RawItem;
use App\Models\Source;
use App\Services\Matching\ListingMatcher;
use App\Services\Scoring\LeadScorer;
use App\Services\Text\TextNormalizer;
use Illuminate\Database\Seeder;

/**
 * Made-up demo leads so the dashboard can be tried without an AI key.
 * Not part of DatabaseSeeder: run with `php artisan db:seed --class=DemoLeadSeeder`.
 * Classification is hand-written; matching and scoring use the real services.
 * No jobs are dispatched (no AI drafts, no Telegram).
 */
class DemoLeadSeeder extends Seeder
{
    public function run(ListingMatcher $matcher, LeadScorer $scorer, TextNormalizer $normalizer): void
    {
        $sources = [
            'telegram' => Source::query()->firstOrCreate(['type' => 'telegram', 'identifier' => 'demo_danang_expats'], ['name' => 'Demo: Da Nang Expats', 'language_hint' => 'en', 'rules_note' => 'Không đăng link trong reply công khai', 'is_active' => true]),
            'telegram_ru' => Source::query()->firstOrCreate(['type' => 'telegram', 'identifier' => 'demo_danang_ru'], ['name' => 'Demo: Дананг чат', 'language_hint' => 'ru', 'is_active' => true]),
            'facebook' => Source::query()->firstOrCreate(['type' => 'facebook', 'identifier' => 'https://www.facebook.com/groups/demo-danang-housing'], ['name' => 'Demo: Da Nang Housing (FB)', 'language_hint' => 'en', 'is_active' => true]),
        ];

        $leads = [
            ['telegram', 1, 'Hi all! Couple with a small cat arriving 1 Dec, looking for a 1BR in An Thuong for 6 months, budget up to 450$. Kitchen is a must.', [
                'stage' => LeadStage::PreArrival, 'language' => 'en', 'summary_vi' => 'Cặp đôi đến 01/12, thuê 6 tháng căn 1PN ở An Thượng, ≤ 450$, nuôi mèo',
                'arrival_date' => now()->addDays(22)->toDateString(), 'duration_months' => 6, 'property_type' => PropertyType::OneBr, 'bedrooms_min' => 1,
                'areas' => ['an-thuong'], 'budget_max_usd' => 450, 'budget_raw' => '450$', 'household' => 'couple with a cat', 'requirements' => ['pet_friendly', 'kitchen']]],
            ['telegram', 3, 'Need a studio near My Khe beach ASAP, my current place ends this Friday. Up to 350 USD, 3 months.', [
                'stage' => LeadStage::Urgent, 'language' => 'en', 'summary_vi' => 'Cần gấp studio gần biển Mỹ Khê, hết nhà thứ Sáu này, ≤ 350$, 3 tháng',
                'duration_months' => 3, 'property_type' => PropertyType::Studio, 'bedrooms_min' => 0, 'areas' => ['my-khe'], 'budget_max_usd' => 350, 'budget_raw' => '350 USD', 'requirements' => []]],
            ['telegram_ru', 5, 'Ищем квартиру 2 спальни в Хай Чау с января, на год, бюджет до 16 млн донгов. Семья с ребёнком.', [
                'stage' => LeadStage::PreArrival, 'language' => 'ru', 'summary_vi' => 'Gia đình có con tìm căn 2PN ở Hải Châu từ tháng 1, thuê 1 năm, ≤ 16 triệu',
                'arrival_date' => now()->addDays(55)->toDateString(), 'duration_months' => 12, 'property_type' => PropertyType::TwoBr, 'bedrooms_min' => 2,
                'areas' => ['hai-chau'], 'budget_max_usd' => 640, 'budget_raw' => '16 млн', 'household' => 'family with a child', 'requirements' => ['family']]],
            ['facebook', 8, 'Already in Da Nang, staying in a hotel. Looking for a house with 3 bedrooms in Son Tra, long term, around 900-1000$. Parking needed.', [
                'stage' => LeadStage::InCitySearching, 'language' => 'en', 'summary_vi' => 'Đang ở khách sạn, tìm nhà 3PN ở Sơn Trà thuê dài hạn, 900–1000$, cần chỗ đậu xe',
                'duration_months' => 12, 'property_type' => PropertyType::House, 'bedrooms_min' => 3, 'areas' => ['son-tra'],
                'budget_min_usd' => 900, 'budget_max_usd' => 1000, 'budget_raw' => '900-1000$', 'requirements' => ['parking']]],
            ['telegram', 20, 'Anyone know a good apartment somewhere in Da Nang? Moving there next year maybe.', [
                'stage' => LeadStage::Unknown, 'language' => 'en', 'summary_vi' => 'Hỏi chung chung về căn hộ ở Đà Nẵng, có thể chuyển đến năm sau',
                'property_type' => PropertyType::Unknown, 'areas' => [], 'requirements' => [], 'confidence' => 0.7]],
            ['telegram', 30, 'Leaving Vietnam end of month, looking for someone to take over my lease: 2BR in Son Tra, 550$/month, 5 months left.', [
                'intent' => LeadIntent::LeavingHandover, 'stage' => LeadStage::Unknown, 'language' => 'en',
                'summary_vi' => 'Rời VN cuối tháng, cần người nhận lại hợp đồng căn 2PN Sơn Trà, 550$/tháng, còn 5 tháng',
                'property_type' => PropertyType::TwoBr, 'bedrooms_min' => 2, 'areas' => ['son-tra'], 'budget_max_usd' => 550, 'budget_raw' => '550$/month', 'requirements' => []]],
            ['facebook', 48, 'Digital nomad here, want a 1BR with fast wifi and workspace in An Thuong or My Khe from mid-December, 4 months, max 500$.', [
                'stage' => LeadStage::PreArrival, 'language' => 'en', 'summary_vi' => 'Digital nomad tìm 1PN có wifi mạnh, góc làm việc ở An Thượng/Mỹ Khê từ giữa 12, 4 tháng, ≤ 500$',
                'arrival_date' => now()->addDays(36)->toDateString(), 'duration_months' => 4, 'property_type' => PropertyType::OneBr, 'bedrooms_min' => 1,
                'areas' => ['an-thuong', 'my-khe'], 'budget_max_usd' => 500, 'budget_raw' => 'max 500$', 'requirements' => ['fast_wifi', 'workspace']]],
        ];

        $listings = Listing::query()->where('is_active', true)->get();

        foreach ($leads as $i => [$sourceKey, $hoursAgo, $text, $attrs]) {
            $source = $sources[$sourceKey];
            $postedAt = now()->subHours($hoursAgo);
            $item = RawItem::query()->updateOrCreate(
                ['source_id' => $source->id, 'external_id' => 'demo:'.($i + 1)],
                [
                    'url' => $source->type->value === 'telegram' ? "https://t.me/{$source->identifier}/".(100 + $i) : $source->identifier,
                    'author_handle' => 'demo_user_'.($i + 1),
                    'author_key' => hash('sha256', 'demo:'.($i + 1)),
                    'text' => $text,
                    'content_hash' => $normalizer->contentHash($text),
                    'posted_at' => $postedAt,
                    'status' => RawItemStatus::Classified,
                    'ai_model' => 'demo',
                ],
            );

            $lead = Lead::query()->updateOrCreate(['raw_item_id' => $item->id], array_merge([
                'intent' => LeadIntent::TenantDemand,
                'confidence' => 0.9,
                'score' => 0,
                'status' => LeadStatus::New,
                'expires_at' => $postedAt->copy()->addDays((int) config('leadradar.leads.expires_after_days')),
            ], $attrs));

            $matches = $matcher->match($lead, $listings);
            $matcher->sync($lead, $matches);
            $lead->score = $scorer->score($lead, $postedAt, now(), $listings->min('price_usd'), $listings->max('price_usd'), $matches->first()['score'] ?? null);
            $lead->save();

            if ($lead->wasRecentlyCreated) {
                $lead->activities()->create(['type' => ActivityType::System, 'body' => 'Lead demo (dữ liệu tự viết, không qua AI).']);
            }
        }

        // One sample pair of drafts so the "Bản nháp trả lời" tab has content.
        $first = Lead::query()->whereHas('rawItem', fn ($q) => $q->where('external_id', 'demo:1'))->first();
        if ($first !== null && $first->replyDrafts()->doesntExist()) {
            $first->replyDrafts()->createMany([
                ['variant' => 'public_reply', 'language' => 'en', 'body' => 'Hi! We have a pet-friendly 1BR in An Thuong within your budget, available from December. Happy to send details by DM.', 'model' => 'demo', 'created_by' => 'system'],
                ['variant' => 'dm', 'language' => 'en', 'body' => "Hi! Saw your post about a 1BR in An Thuong. We have Sea Breeze 1BR at 420\$/month, cats welcome, full kitchen. Would you like photos?", 'model' => 'demo', 'created_by' => 'system'],
            ]);
        }

        $this->command?->info('Demo leads: '.count($leads));
    }
}
