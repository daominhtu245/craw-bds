<?php

namespace App\Enums;

enum KeywordKind: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Area = 'area';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
