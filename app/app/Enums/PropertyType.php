<?php

namespace App\Enums;

enum PropertyType: string
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
}
