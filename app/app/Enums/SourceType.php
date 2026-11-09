<?php

namespace App\Enums;

enum SourceType: string
{
    case Telegram = 'telegram';
    case Facebook = 'facebook';
    case Forum = 'forum';
    case Reddit = 'reddit';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
