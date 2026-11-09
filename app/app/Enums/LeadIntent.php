<?php

namespace App\Enums;

/**
 * All intents the classifier can return. Only some of them create a lead.
 */
enum LeadIntent: string
{
    case TenantDemand = 'tenant_demand';
    case LeavingHandover = 'leaving_handover';
    case LandlordOffer = 'landlord_offer';
    case Agent = 'agent';
    case ShortTerm = 'short_term';
    case OtherCity = 'other_city';
    case Other = 'other';

    public function createsLead(): bool
    {
        return in_array($this, [self::TenantDemand, self::LeavingHandover], true);
    }

    /** Intents that may be stored on the leads table. */
    public static function leadValues(): array
    {
        return [self::TenantDemand->value, self::LeavingHandover->value];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
