<?php

namespace App\Enums;

enum FilterReason: string
{
    case Duplicate = 'duplicate';
    case LangVi = 'lang_vi';
    case NegativeKw = 'negative_kw';
    case NoPositiveKw = 'no_positive_kw';
    case TooShort = 'too_short';
    case KnownAgent = 'known_agent';
    case TooOld = 'too_old';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
