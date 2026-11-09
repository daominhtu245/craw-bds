<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PropertyType: string implements HasLabel
{
    case Studio = 'studio';
    case OneBr = '1br';
    case TwoBr = '2br';
    case ThreeBrPlus = '3br_plus';
    case House = 'house';
    case Villa = 'villa';
    case Room = 'room';
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Studio => 'Studio',
            self::OneBr => '1 PN',
            self::TwoBr => '2 PN',
            self::ThreeBrPlus => '3+ PN',
            self::House => 'Nhà',
            self::Villa => 'Villa',
            self::Room => 'Phòng',
            self::Unknown => 'Chưa rõ',
        };
    }
}
