<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Services\Classify\FieldExtractor;
use App\Services\Classify\RuleClassifier;
use App\Services\Drafts\TemplateDrafter;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\KeywordSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Vietnamese tenants are in scope (owner decision 2026-11-11). Texts are self-written.
 */
class VietnameseLeadsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function rules(string $text): array
    {
        return app(RuleClassifier::class)->analyze($text, Carbon::parse('2026-11-09T03:00:00Z'));
    }

    public function test_fields_from_vietnamese_text(): void
    {
        $f = app(FieldExtractor::class);
        $posted = Carbon::parse('2026-11-09T03:00:00Z');

        $this->assertSame(12, $f->durationMonths('thuê 1 năm'));
        $this->assertSame(6, $f->durationMonths('o nua nam'));
        $this->assertSame(6, $f->durationMonths('ở khoảng 6 tháng'));

        $this->assertSame([PropertyType::TwoBr, 2], $f->propertyType('căn hộ 2 phòng ngủ'));
        $this->assertSame([PropertyType::OneBr, 1], $f->propertyType('can ho 1pn'));
        $this->assertSame(PropertyType::House, $f->propertyType('thuê nhà nguyên căn 3 tầng')[0]);
        $this->assertSame(PropertyType::Room, $f->propertyType('tim phong tro gan truong')[0]);

        $this->assertTrue($f->mentionsShortStay('đi du lịch 3 đêm'));
        $this->assertTrue($f->mentionsShortStay('thue vai ngay'));
        $this->assertFalse($f->mentionsShortStay('đã ở đây 2 tuần, cần thuê 6 tháng'));

        $this->assertSame('2026-12-01', $f->arrivalDate('từ tháng 12, ở 6 tháng', $posted));
        $this->assertSame('2027-01-15', $f->arrivalDate('dự kiến đến ngày 15 tháng 1', $posted));
        $this->assertSame('2026-12-20', $f->arrivalDate('nhận nhà ngày 20/12', $posted));
        $this->assertSame('2026-12-01', $f->arrivalDate('tháng sau mình chuyển ra', $posted));
        $this->assertNull($f->arrivalDate('thuê 6 tháng', $posted));

        $budget = $f->budget('ngân sách 10-12 củ');
        $this->assertSame([10_000_000.0, 12_000_000.0, 'VND'], [$budget['min'], $budget['max'], $budget['currency']]);
    }

    public static function intents(): array
    {
        return [
            'tenant accented' => ['Mình cần thuê căn hộ 1 phòng ngủ ở An Thượng, ngân sách 9 triệu', 'tenant_demand'],
            'tenant unaccented' => ['can thue can ho 2pn gan bien, o 1 nam', 'tenant_demand'],
            'tenant asking for rentals' => ['Ai có căn hộ cho thuê khu Sơn Trà không ạ, mình cần 2 phòng ngủ', 'tenant_demand'],
            'landlord' => ['Chính chủ cho thuê căn 1PN, còn trống, LH zalo', 'landlord_offer'],
            'landlord seeking tenant' => ['Mình cần tìm người thuê căn studio ở Hải Châu, giá 5 triệu', 'landlord_offer'],
            'agent' => ['Bên em có nhiều căn hộ khắp Đà Nẵng, hỗ trợ tìm căn miễn phí, inbox em', 'agent'],
            'handover' => ['Mình về nước nên cần sang lại hợp đồng căn 2PN, còn 5 tháng', 'leaving_handover'],
            'short stay' => ['Cần tìm homestay theo ngày cho 3 đêm', 'short_term'],
            'other city' => ['Tìm thuê căn hộ ở Hà Nội khu Cầu Giấy', 'other_city'],
        ];
    }

    #[DataProvider('intents')]
    public function test_intents(string $text, string $intent): void
    {
        $this->assertSame($intent, $this->rules($text)['intent']);
    }

    public function test_vietnamese_eval_set_passes_with_keywords(): void
    {
        $this->artisan('leadradar:eval', ['--engine' => 'keyword', '--file' => 'tests/Fixtures/samples_vi.jsonl'])
            ->expectsOutputToContain('PASS')
            ->assertSuccessful();
    }

    public function test_vietnamese_reply_template_is_used(): void
    {
        $templates = TemplateDrafter::templates();

        $this->assertArrayHasKey('vi', $templates);
        $this->assertStringContainsString('{agency}', $templates['vi']['dm']);
    }

    public function test_unaccented_vietnamese_is_detected_without_catching_english(): void
    {
        $detector = new \App\Services\Prefilter\LanguageDetector(0.30);

        $this->assertSame('vi', $detector->detect('can thue can ho 2pn gan bien My Khe, o 1 nam, ngan sach 12tr'));
        $this->assertSame('vi', $detector->detect('Can nhuong lai can ho 1pn khu Ngu Hanh Son, hop dong con 4 thang'));
        $this->assertSame('en', $detector->detect('We live in Hai Chau, the house is near the river, any tips?'));
        $this->assertSame('en', $detector->detect('Can anyone recommend a good dentist near Nguyen Van Linh street?'));
    }

    public function test_unaccent_helper(): void
    {
        $this->assertSame('can thue', KeywordSeeder::unaccent('cần thuê'));
        $this->assertSame('da nang', KeywordSeeder::unaccent('đà nẵng'));
        $this->assertSame('lh', KeywordSeeder::unaccent('lh'));
    }
}
