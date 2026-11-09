<?php

namespace Tests\Unit;

use App\Services\Prefilter\LanguageDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LanguageDetectorTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['Looking for a 1BR in An Thuong', 'en'],
            ['Ищу квартиру на полгода', 'ru'],
            ['다낭에서 아파트 찾고 있어요', 'ko'],
            ['我们在岘港找长期公寓', 'zh'],
            ['Mình cần thuê căn hộ 1 phòng ngủ ở Hải Châu, giá khoảng 8 triệu', 'vi'],
            ['Cho thuê nhà nguyên căn gần biển', 'vi'],
            // English with a few Vietnamese place names stays English.
            ['Looking for an apartment near Mỹ Khê or Sơn Trà, long term, budget 500 USD', 'en'],
            ['Ищу квартиру в районе Mỹ Khê', 'ru'],
            ['12345 !!!', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_detect(string $text, ?string $expected): void
    {
        $this->assertSame($expected, (new LanguageDetector(0.30))->detect($text));
    }
}
