<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Assigned = 'assigned';
    case Contacted = 'contacted';
    case Viewing = 'viewing';
    case Negotiating = 'negotiating';
    case Won = 'won';
    case Lost = 'lost';
    case Invalid = 'invalid';
    case Archived = 'archived';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
