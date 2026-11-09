<?php

namespace App\Enums;

enum LeadStage: string
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
}
