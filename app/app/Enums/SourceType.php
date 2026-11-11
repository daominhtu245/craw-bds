<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SourceType: string implements HasLabel
{
    case Telegram = 'telegram';
    case Facebook = 'facebook';
    case Forum = 'forum';
    case Reddit = 'reddit';
    case Threads = 'threads';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
