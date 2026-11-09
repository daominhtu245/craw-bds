<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LeadStage: string implements HasColor, HasLabel
{
    case PreArrival = 'pre_arrival';
    case InCitySearching = 'in_city_searching';
    case Urgent = 'urgent';
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PreArrival => 'Chưa sang VN',
            self::InCitySearching => 'Đang ở Đà Nẵng',
            self::Urgent => 'Cần gấp',
            self::Unknown => 'Chưa rõ',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Urgent => 'danger',
            self::InCitySearching => 'warning',
            self::PreArrival => 'info',
            self::Unknown => 'gray',
        };
    }
}
