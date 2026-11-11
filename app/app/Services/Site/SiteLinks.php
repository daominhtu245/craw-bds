<?php

namespace App\Services\Site;

use App\Models\Lead;
use App\Models\Listing;

/**
 * Links to the public site (App #2) with a tracking code, for reply drafts
 * (template and AI). A request sent from such a link joins the lead.
 */
class SiteLinks
{
    /** Best matched public listing when there is one, otherwise the listing page. Empty when the site is off. */
    public function forLead(Lead $lead, ?Listing $best, string $language): string
    {
        if (! config('leadradar.site.enabled')) {
            return '';
        }

        $locale = in_array($language, (array) config('leadradar.site.locales'), true) ? $language : 'en';
        $ref = LeadRef::encode($lead->id);

        if ($best !== null && $best->is_public && $best->slug) {
            return route('site.listing', ['locale' => $locale, 'listing' => $best->slug, 'ref' => $ref]);
        }

        return route('site.index', ['locale' => $locale, 'ref' => $ref]);
    }
}
