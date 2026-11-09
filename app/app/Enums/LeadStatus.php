<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LeadStatus: string implements HasColor, HasLabel
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

    /** Statuses that require a note when chosen (SPEC 14.2). */
    public function requiresNote(): bool
    {
        return in_array($this, [self::Lost, self::Invalid], true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Mới',
            self::Assigned => 'Đã nhận',
            self::Contacted => 'Đã liên hệ',
            self::Viewing => 'Đi xem nhà',
            self::Negotiating => 'Đang thương lượng',
            self::Won => 'Thành công',
            self::Lost => 'Mất khách',
            self::Invalid => 'Không hợp lệ',
            self::Archived => 'Lưu trữ',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Assigned, self::Contacted, self::Viewing, self::Negotiating => 'warning',
            self::Won => 'success',
            self::Lost, self::Invalid => 'danger',
            self::Archived => 'gray',
        };
    }
}
