<?php

namespace Tests\Feature;

use App\Enums\FilterReason;
use App\Enums\RawItemStatus;
use App\Jobs\ClassifyItemJob;
use App\Jobs\PrefilterItemJob;
use App\Models\Keyword;
use App\Models\KnownAgent;
use App\Models\RawItem;
use App\Models\Source;
use App\Services\Prefilter\Prefilter;
use App\Services\Text\TextNormalizer;
use Database\Seeders\KeywordSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrefilterTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KeywordSeeder::class);
        $this->source = Source::create(['type' => 'telegram', 'name' => 'Test', 'identifier' => 'test']);
    }

    private function item(string $text, array $attrs = []): RawItem
    {
        $this->seq++;

        return RawItem::create(array_merge([
            'source_id' => $this->source->id,
            'external_id' => 'x:'.$this->seq,
            'url' => 'https://t.me/test/'.$this->seq,
            'author_key' => hash('sha256', 'telegram:'.$this->seq),
            'text' => $text,
            'content_hash' => (new TextNormalizer)->contentHash($text),
            'posted_at' => now()->subHour(),
            'status' => RawItemStatus::New,
        ], $attrs));
    }

    private function reasonFor(RawItem $item): ?FilterReason
    {
        return app(Prefilter::class)->check($item)->reason;
    }

    public static function passingTexts(): array
    {
        return [
            'en demand' => ['Looking for a 1BR in An Thuong from December, 6 months, budget 450$'],
            'ru demand' => ['Ищем двушку на полгода в районе Ми Кхе, переезжаем в январе'],
            'housing word only' => ['Any idea what a flat near the river costs these days?'],
            'strong positive beats negatives' => ['Looking for a studio, not interested in agents, no "price:" spam or whatsapp please'],
            'korean housing via AI' => ['다낭에서 아파트 rent 찾고 있어요 6개월'],
        ];
    }

    #[DataProvider('passingTexts')]
    public function test_relevant_texts_pass(string $text): void
    {
        $this->assertNull($this->reasonFor($this->item($text)));
    }

    public function test_too_old(): void
    {
        $this->assertSame(FilterReason::TooOld, $this->reasonFor($this->item('Looking for a 1BR in An Thuong', ['posted_at' => now()->subDays(15)])));
        $this->assertSame(FilterReason::TooOld, $this->reasonFor($this->item('Ищу квартиру на год в Дананге', ['posted_at' => now()->subMonths(2)])));
        $this->assertNull($this->reasonFor($this->item('Looking for a 2BR near the beach', ['posted_at' => now()->subDays(13)])));
    }

    public function test_too_short(): void
    {
        $this->assertSame(FilterReason::TooShort, $this->reasonFor($this->item('need flat')));
        $this->assertSame(FilterReason::TooShort, $this->reasonFor($this->item('🏠 ищу  https://example.com/very/long/url/that/is/stripped')));
    }

    public function test_duplicate_only_flags_later_copies(): void
    {
        $first = $this->item('Looking for a 1BR in An Thuong for 6 months');
        $second = $this->item('looking for a 1br in an thuong for 6 months  ');
        $third = $this->item('Looking for a 1BR   in An Thuong for 6 months https://t.me/x');

        $this->assertNull($this->reasonFor($first));
        $this->assertSame(FilterReason::Duplicate, $this->reasonFor($second));
        $this->assertSame(FilterReason::Duplicate, $this->reasonFor($third));
    }

    public function test_duplicate_window_is_30_days(): void
    {
        $old = $this->item('Searching for a house in Son Tra for a year');
        $old->forceFill(['created_at' => now()->subDays(31)])->save();

        $this->assertNull($this->reasonFor($this->item('Searching for a house in Son Tra for a year')));
    }

    public function test_known_agent(): void
    {
        $key = hash('sha256', 'telegram:agent');
        KnownAgent::create(['author_key' => $key, 'reason' => 'test']);

        $this->assertSame(FilterReason::KnownAgent, $this->reasonFor($this->item('Looking for tenants? I have 1BR in An Thuong', ['author_key' => $key])));
        $this->assertSame(FilterReason::KnownAgent, $this->reasonFor($this->item('Ищу арендаторов на квартиру в центре', ['author_key' => $key])));
    }

    public function test_vietnamese(): void
    {
        $this->assertSame(FilterReason::LangVi, $this->reasonFor($this->item('Mình cần thuê căn hộ 1 phòng ngủ ở Hải Châu, giá khoảng 8 triệu, ai có thì nhắn mình nhé.')));
        $this->assertSame(FilterReason::LangVi, $this->reasonFor($this->item('Cho thuê căn hộ studio gần biển Mỹ Khê, đầy đủ nội thất, giá tốt')));
    }

    public function test_negative_keywords(): void
    {
        $this->assertSame(FilterReason::NegativeKw, $this->reasonFor($this->item('For rent: studio near My Khe, available now, contact me on whatsapp')));
        $this->assertSame(FilterReason::NegativeKw, $this->reasonFor($this->item('Сдаю квартиру в центре, свободна с декабря, цена: 12 млн, пишите в лс')));
    }

    public static function englishListingTexts(): array
    {
        return [
            'spaced price colon' => ["2BR APARTMENT NEAR AN THUONG\nPrice : 15 million VND / month\nCall the number below to view the apartment"],
            'hot deal' => ["HOT DEAL - furnished studio, Hai Chau\nRent: 9,000,000 VND/month, negotiable\nMessage me for more photos or to arrange a viewing"],
            'rental terms' => ["Cozy 1BR by the river\nRental terms: minimum stay 3 months, deposit 1 month\nMore units in my Telegram group"],
        ];
    }

    #[DataProvider('englishListingTexts')]
    public function test_english_listing_phrases(string $text): void
    {
        $this->assertSame(FilterReason::NegativeKw, $this->reasonFor($this->item($text)));
    }

    public function test_tenant_mentioning_negotiable_budget_still_passes(): void
    {
        $this->assertNull($this->reasonFor($this->item('Looking for a 2BR near My Khe for a year, budget 600$ negotiable, can arrange a viewing any day')));
    }

    public function test_no_positive_keyword_and_no_housing_word(): void
    {
        $this->assertSame(FilterReason::NoPositiveKw, $this->reasonFor($this->item('Where can I buy a good motorbike helmet in Da Nang?')));
        $this->assertSame(FilterReason::NoPositiveKw, $this->reasonFor($this->item('Кто знает хорошего стоматолога в городе?')));
    }

    public function test_regex_and_inactive_keywords(): void
    {
        Keyword::create(['language' => 'en', 'kind' => 'positive', 'term' => 'need\s+(a\s+)?home', 'weight' => 2, 'is_regex' => true]);
        Keyword::query()->where('term', 'whatsapp')->update(['is_active' => false]);

        $result = app(Prefilter::class)->analyzeText('We need a home for next year, whatsapp group said ask here');
        $this->assertTrue($result->hasStrongPositive);
        $this->assertNotContains('whatsapp', $result->negativeMatches);
    }

    public function test_job_updates_status_and_dispatches_classification(): void
    {
        Queue::fake();
        $pass = $this->item('Looking for a 1BR in An Thuong for 6 months');
        $fail = $this->item('Where can I buy a good motorbike helmet in Da Nang?');

        (new PrefilterItemJob($pass->id))->handle(app(Prefilter::class));
        (new PrefilterItemJob($fail->id))->handle(app(Prefilter::class));

        $this->assertSame(RawItemStatus::Queued, $pass->fresh()->status);
        $this->assertSame('en', $pass->fresh()->language);
        $this->assertSame(RawItemStatus::FilteredOut, $fail->fresh()->status);
        $this->assertSame(FilterReason::NoPositiveKw, $fail->fresh()->filter_reason);
        Queue::assertPushedOn('ai', ClassifyItemJob::class, fn ($job) => $job->rawItemId === $pass->id);
        Queue::assertPushed(ClassifyItemJob::class, 1);

        // Running again is a no-op.
        (new PrefilterItemJob($pass->id))->handle(app(Prefilter::class));
        Queue::assertPushed(ClassifyItemJob::class, 1);
    }
}
