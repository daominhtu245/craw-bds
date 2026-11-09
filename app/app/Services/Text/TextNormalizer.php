<?php

namespace App\Services\Text;

/**
 * Text normalization rules from SPEC 8: lowercase, strip URLs, collapse
 * whitespace. Emoji are removed only for keyword matching.
 */
class TextNormalizer
{
    private const URL_PATTERN = '~(?:https?://|www\.)\S+~iu';

    /** Emoji, pictographs, dingbats, flags, variation selectors and ZWJ. */
    private const EMOJI_PATTERN = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u';

    public function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace(self::URL_PATTERN, ' ', $text) ?? $text;

        return $this->collapseWhitespace($text);
    }

    public function forMatching(string $text): string
    {
        $text = preg_replace(self::EMOJI_PATTERN, ' ', $this->normalize($text)) ?? $text;

        return $this->collapseWhitespace($text);
    }

    public function contentHash(string $text): string
    {
        return hash('sha256', $this->normalize($text));
    }

    /** Short, single-line excerpt for logs (SPEC 15: never log full text). */
    public static function excerpt(?string $text, int $length = 80): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');

        return mb_strimwidth($text, 0, $length, '…', 'UTF-8');
    }

    private function collapseWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
