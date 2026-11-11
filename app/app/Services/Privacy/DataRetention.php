<?php

namespace App\Services\Privacy;

use App\Enums\LeadStatus;
use App\Enums\RawItemStatus;
use App\Models\Inquiry;
use App\Models\Lead;
use App\Models\RawItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily data clean-up (SPEC 15).
 *
 * 1. raw_items filtered_out / classified without a lead: deleted after 14 days.
 * 2. leads new / assigned / lost / invalid past expires_at: lead, raw item,
 *    drafts, matches and activities deleted (FK cascades).
 * 3. won leads: kept, but the original text is anonymised after 180 days.
 * 4. inquiries (App #2): the name and contact a customer typed in are erased
 *    after leadradar.site.contact_retention_days (default 90).
 */
class DataRetention
{
    public const RAW_ITEM_DAYS = 14;

    public const WON_ANONYMISE_DAYS = 180;

    public const ANONYMISED_TEXT = '[đã ẩn danh]';

    /** @return array{raw_items: int, leads: int, anonymised: int, inquiry_contacts: int} */
    public function run(): array
    {
        $result = [
            'raw_items' => $this->purgeRawItems(),
            'leads' => $this->purgeExpiredLeads(),
            'anonymised' => $this->anonymiseWonLeads(),
            'inquiry_contacts' => $this->purgeInquiryContacts(),
        ];

        Log::info('retention.done', $result + ['job' => 'DataRetention']);

        return $result;
    }

    public function purgeRawItems(): int
    {
        $deleted = 0;

        RawItem::query()
            ->whereIn('status', [RawItemStatus::FilteredOut->value, RawItemStatus::Classified->value])
            ->where('created_at', '<', now()->subDays(self::RAW_ITEM_DAYS))
            ->whereDoesntHave('lead')
            ->select('id')
            ->chunkById(500, function ($items) use (&$deleted) {
                $deleted += RawItem::query()->whereKey($items->modelKeys())->delete();
            });

        return $deleted;
    }

    public function purgeExpiredLeads(): int
    {
        $statuses = [LeadStatus::New, LeadStatus::Assigned, LeadStatus::Lost, LeadStatus::Invalid];
        $deleted = 0;

        Lead::query()
            ->whereIn('status', array_map(fn ($s) => $s->value, $statuses))
            ->where('expires_at', '<', now())
            ->select(['id', 'raw_item_id'])
            ->chunkById(200, function ($leads) use (&$deleted) {
                DB::transaction(function () use ($leads, &$deleted) {
                    // Deleting the raw item cascades to the lead, its drafts, matches and activities.
                    RawItem::query()->whereKey($leads->pluck('raw_item_id'))->delete();
                    $deleted += $leads->count();
                });
            });

        return $deleted;
    }

    public function purgeInquiryContacts(): int
    {
        return Inquiry::query()
            ->whereNull('contact_purged_at')
            ->where('created_at', '<', now()->subDays((int) config('leadradar.site.contact_retention_days')))
            ->update(['name' => null, 'contact' => null, 'contact_purged_at' => now(), 'updated_at' => now()]);
    }

    public function anonymiseWonLeads(): int
    {
        return RawItem::query()
            ->whereHas('lead', fn ($q) => $q->where('status', LeadStatus::Won->value))
            // Same reference point as leads.expires_at: the original post date.
            ->where('posted_at', '<', now()->subDays(self::WON_ANONYMISE_DAYS))
            ->where('text', '!=', self::ANONYMISED_TEXT)
            ->update([
                'text' => self::ANONYMISED_TEXT,
                'context_text' => null,
                'author_handle' => null,
                'classification' => null,
                'updated_at' => now(),
            ]);
    }
}
