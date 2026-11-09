<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * positive / negative feed the prefilter (SPEC 8). The intent_* and stage_*
 * kinds feed the keyword classifier used when AI is off (RuleClassifier).
 */
enum KeywordKind: string implements HasLabel
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Area = 'area';
    case IntentTenant = 'intent_tenant';
    case IntentHandover = 'intent_handover';
    case IntentLandlord = 'intent_landlord';
    case IntentAgent = 'intent_agent';
    case IntentShortTerm = 'intent_short_term';
    case IntentOtherCity = 'intent_other_city';
    case StageUrgent = 'stage_urgent';
    case StageInCity = 'stage_in_city';
    case StagePreArrival = 'stage_pre_arrival';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Kinds that map to a lead intent in the keyword classifier. */
    public function intent(): ?LeadIntent
    {
        return match ($this) {
            self::IntentTenant => LeadIntent::TenantDemand,
            self::IntentHandover => LeadIntent::LeavingHandover,
            self::IntentLandlord => LeadIntent::LandlordOffer,
            self::IntentAgent => LeadIntent::Agent,
            self::IntentShortTerm => LeadIntent::ShortTerm,
            self::IntentOtherCity => LeadIntent::OtherCity,
            default => null,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Positive => 'Prefilter: dương',
            self::Negative => 'Prefilter: âm',
            self::Area => 'Khu vực',
            self::IntentTenant => 'Phân loại: khách tìm thuê',
            self::IntentHandover => 'Phân loại: nhượng hợp đồng',
            self::IntentLandlord => 'Phân loại: chủ nhà cho thuê',
            self::IntentAgent => 'Phân loại: môi giới',
            self::IntentShortTerm => 'Phân loại: thuê ngắn ngày',
            self::IntentOtherCity => 'Phân loại: thành phố khác',
            self::StageUrgent => 'Giai đoạn: cần gấp',
            self::StageInCity => 'Giai đoạn: đã ở Đà Nẵng',
            self::StagePreArrival => 'Giai đoạn: chưa sang',
        };
    }
}
