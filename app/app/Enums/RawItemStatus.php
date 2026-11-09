<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RawItemStatus: string implements HasColor, HasLabel
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

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Mới',
            self::FilteredOut => 'Bị lọc',
            self::Queued => 'Chờ AI',
            self::Classified => 'Đã phân loại',
            self::Error => 'Lỗi',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New, self::Queued => 'info',
            self::FilteredOut => 'gray',
            self::Classified => 'success',
            self::Error => 'danger',
        };
    }
}
