<?php

namespace App\Services\Prefilter;

/**
 * Cheap script-based language guess for prefiltering (SPEC 8, step 5).
 * The AI classifier returns the authoritative language later.
 */
class LanguageDetector
{
    private const VI_CHARS = 'àáảãạăằắẳẵặâầấẩẫậèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ';

    /**
     * Unaccented Vietnamese words that are not English words. "can", "ban", "nam", "may"
     * and similar are left out on purpose.
     */
    private const VI_PLAIN_WORDS = [
        'thue', 'nha', 'phong', 'khong', 'duoc', 'nhe', 'minh', 'trieu', 'gan', 'bien', 'tim', 'thang',
        'khu', 'chung', 'ngu', 'gia', 'dinh', 'nguoi', 'cua', 'voi', 'nhung', 'hoac', 'mot', 'hai',
        'tang', 'duong', 'quan', 'dai', 'han', 'ngay', 'tuan', 'sinh', 'vien', 'xin', 'cam', 'on', 'oi', 'ngan', 'sach', 'ho',
    ];

    public function __construct(private readonly float $viWordRatio = 0.30) {}

    public function detect(string $text): ?string
    {
        $text = mb_strtolower($text, 'UTF-8');

        $counts = [
            'cyrillic' => preg_match_all('/\p{Cyrillic}/u', $text),
            'hangul' => preg_match_all('/\p{Hangul}/u', $text),
            'han' => preg_match_all('/\p{Han}/u', $text),
            'latin' => preg_match_all('/\p{Latin}/u', $text),
        ];

        $letters = array_sum($counts);
        if ($letters === 0) {
            return null;
        }

        arsort($counts);
        $dominant = array_key_first($counts);

        return match ($dominant) {
            'cyrillic' => 'ru',
            'hangul' => 'ko',
            'han' => 'zh',
            default => $this->isVietnamese($text) ? 'vi' : 'en',
        };
    }

    /**
     * Vietnamese when a large share of Latin words carry a Vietnamese diacritic.
     */
    public function isVietnamese(string $text): bool
    {
        preg_match_all('/\p{Latin}+/u', mb_strtolower($text, 'UTF-8'), $m);
        $words = $m[0];

        if (count($words) === 0) {
            return false;
        }

        $pattern = '/['.self::VI_CHARS.']/u';
        $vi = count(array_filter($words, fn (string $w) => preg_match($pattern, $w) === 1));

        if ($vi / count($words) > $this->viWordRatio) {
            return true;
        }

        // Typed without diacritics ("can thue can ho 2pn gan bien"): enough distinctly Vietnamese words.
        $plain = array_intersect($words, self::VI_PLAIN_WORDS);

        return count(array_unique($plain)) >= 3 && count($plain) / count($words) >= 0.2;
    }
}
