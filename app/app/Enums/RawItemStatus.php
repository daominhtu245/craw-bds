<?php

namespace App\Enums;

enum RawItemStatus: string
{
    case New = 'new';
    case FilteredOut = 'filtered_out';
    case Queued = 'queued';
    case Classified = 'classified';
    case Error = 'error';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
