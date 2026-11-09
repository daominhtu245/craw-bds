<?php

namespace Tests\Unit;

use App\Services\Text\TextNormalizer;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase
{
    public function test_normalize_lowercases_strips_urls_and_collapses_whitespace(): void
    {
        $n = new TextNormalizer;

        $this->assertSame(
            'looking for apartment see',
            $n->normalize("  Looking   FOR\nApartment see https://example.com/x?y=1  "),
        );
        $this->assertSame('ищу квартиру', $n->normalize('ИЩУ   Квартиру www.site.ru/page'));
    }

    public function test_normalize_keeps_emoji_but_for_matching_removes_them(): void
    {
        $n = new TextNormalizer;

        $this->assertSame('need 🏠 now 🙏', $n->normalize('Need 🏠 now 🙏'));
        $this->assertSame('need now', $n->forMatching('Need 🏠 now 🙏'));
        $this->assertSame('flag test', $n->forMatching('Flag 🇷🇺 test'));
    }

    public function test_content_hash_ignores_case_whitespace_and_urls(): void
    {
        $n = new TextNormalizer;

        $this->assertSame(
            $n->contentHash('Looking for a 1BR https://a.example'),
            $n->contentHash("looking  for a 1br\n https://b.example/other"),
        );
        $this->assertNotSame($n->contentHash('Looking for a 1BR'), $n->contentHash('Looking for a 2BR'));
    }

    public function test_excerpt_is_short_and_single_line(): void
    {
        $excerpt = TextNormalizer::excerpt(str_repeat("word\n", 50));

        $this->assertLessThanOrEqual(80, mb_strlen($excerpt));
        $this->assertStringNotContainsString("\n", $excerpt);
    }
}
