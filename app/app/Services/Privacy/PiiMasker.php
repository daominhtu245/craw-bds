<?php

namespace App\Services\Privacy;

/**
 * Masks phone numbers and e-mail addresses before anything is stored (SPEC 15).
 *
 * Phone heuristic: a run of digits with common separators, 9–15 digits in
 * total, that starts with "+" or "0", or has at least 10 digits. This keeps
 * prices like "15 000 000 VND" (8 digits) or "100 000 000" intact.
 */
class PiiMasker
{
    public const PHONE_TOKEN = '[phone]';

    public const EMAIL_TOKEN = '[email]';

    private const EMAIL_PATTERN = '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u';

    private const PHONE_CANDIDATE = '/(?<![\p{L}\p{N}])\+?\(?\d[\d\s().\-]{6,}\d(?![\p{L}\p{N}])/u';

    public function mask(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $text = preg_replace(self::EMAIL_PATTERN, self::EMAIL_TOKEN, $text) ?? $text;

        return preg_replace_callback(self::PHONE_CANDIDATE, function (array $m): string {
            return $this->looksLikePhone($m[0]) ? self::PHONE_TOKEN : $m[0];
        }, $text) ?? $text;
    }

    private function looksLikePhone(string $candidate): bool
    {
        $digits = preg_replace('/\D/', '', $candidate) ?? '';
        $count = strlen($digits);

        if ($count < 9 || $count > 15) {
            return false;
        }

        // ISO dates followed by a time ("2026-12-15 10:30") are not phones.
        if (preg_match('/\d{4}-\d{2}-\d{2}/', $candidate)) {
            return false;
        }

        $trimmed = ltrim($candidate, '(');

        return str_starts_with($trimmed, '+') || str_starts_with($digits, '0') || $count >= 10;
    }
}
