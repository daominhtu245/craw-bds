<?php

namespace App\Enums;

enum ActivityType: string
{
    case StatusChange = 'status_change';
    case Note = 'note';
    case ReplySent = 'reply_sent';
    case AiDraft = 'ai_draft';
    case System = 'system';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
