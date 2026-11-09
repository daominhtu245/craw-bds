<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FilterReason: string implements HasLabel
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

    public function getLabel(): string
    {
        return match ($this) {
            self::Duplicate => 'Trùng nội dung',
            self::LangVi => 'Tiếng Việt',
            self::NegativeKw => 'Keyword âm',
            self::NoPositiveKw => 'Không có keyword',
            self::TooShort => 'Quá ngắn',
            self::KnownAgent => 'Môi giới đã biết',
            self::TooOld => 'Quá cũ',
        };
    }
}
