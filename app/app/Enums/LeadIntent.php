<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * All intents the classifier can return. Only some of them create a lead.
 */
enum LeadIntent: string implements HasColor, HasLabel
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

    public function getLabel(): string
    {
        return match ($this) {
            self::TenantDemand => 'Cần thuê',
            self::LeavingHandover => 'Nguồn hàng',
            self::LandlordOffer => 'Chủ nhà rao',
            self::Agent => 'Môi giới',
            self::ShortTerm => 'Ngắn hạn',
            self::OtherCity => 'Thành phố khác',
            self::Other => 'Khác',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::TenantDemand => 'success',
            self::LeavingHandover => 'primary',
            default => 'gray',
        };
    }
}
