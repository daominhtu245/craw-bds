<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum KeywordKind: string implements HasLabel
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Area = 'area';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Positive => 'Dương',
            self::Negative => 'Âm',
            self::Area => 'Khu vực',
        };
    }
}
