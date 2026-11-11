<?php

namespace App\Services\Site;

/**
 * Short tracking code for links in reply drafts ("?ref=1x-a3f9c2"), so an
 * inquiry from the public site is attached to the lead it came from.
 * Signed with APP_KEY: a guessed code does not resolve.
 */
class LeadRef
{
    public static function encode(int $leadId): string
    {
        return base_convert((string) $leadId, 10, 36).'-'.self::signature($leadId);
    }

    public static function decode(?string $ref): ?int
    {
        if ($ref === null || ! preg_match('/^([0-9a-z]{1,10})-([0-9a-f]{6})$/', strtolower(trim($ref)), $m)) {
            return null;
        }

        $id = (int) base_convert($m[1], 36, 10);

        return $id > 0 && hash_equals(self::signature($id), $m[2]) ? $id : null;
    }

    private static function signature(int $leadId): string
    {
        return substr(hash_hmac('sha256', 'lead-ref:'.$leadId, (string) config('app.key')), 0, 6);
    }
}
