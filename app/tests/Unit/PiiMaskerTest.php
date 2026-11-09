<?php

namespace Tests\Unit;

use App\Services\Privacy\PiiMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PiiMaskerTest extends TestCase
{
    public static function maskedCases(): array
    {
        return [
            'vn mobile plain' => ['Call 0905123456 please', 'Call [phone] please'],
            'vn mobile spaced' => ['Zalo 0905 123 456', 'Zalo [phone]'],
            'vn mobile dotted' => ['sdt 090.512.3456', 'sdt [phone]'],
            'vn intl' => ['WhatsApp +84 905 123 456 anytime', 'WhatsApp [phone] anytime'],
            'vn intl compact' => ['+84905123456', '[phone]'],
            'ru intl brackets' => ['Звоните +7 (912) 345-67-89', 'Звоните [phone]'],
            'ru domestic 8' => ['тел 8 912 345 67 89', 'тел [phone]'],
            'us intl' => ['Call +1-202-555-0143 now', 'Call [phone] now'],
            'uk intl' => ['ring +44 20 7946 0958', 'ring [phone]'],
            'email' => ['mail me: john.doe+rent@example.co.uk', 'mail me: [email]'],
            'cyrillic email text' => ['пишите на ivan_petrov@mail.ru спасибо', 'пишите на [email] спасибо'],
            'two phones' => ['0905123456 or 0935123456', '[phone] or [phone]'],
        ];
    }

    #[DataProvider('maskedCases')]
    public function test_it_masks_contacts(string $input, string $expected): void
    {
        $this->assertSame($expected, (new PiiMasker)->mask($input));
    }

    public static function untouchedCases(): array
    {
        return [
            'vnd budget spaced' => ['Budget 15 000 000 VND per month'],
            'vnd budget dotted' => ['up to 12.000.000 dong'],
            'vnd budget 100m' => ['total 100 000 000 for a year'],
            'usd budget' => ['budget 450$ - 600$'],
            'iso date' => ['arriving 2026-12-15'],
            'iso date with time' => ['arriving 2026-12-15 10:30'],
            'price range' => ['400-500 usd'],
            'telegram handle' => ['ping @danang_helper here'],
            'short number' => ['room 1203 in tower 2'],
        ];
    }

    #[DataProvider('untouchedCases')]
    public function test_it_leaves_non_contacts_alone(string $input): void
    {
        $this->assertSame($input, (new PiiMasker)->mask($input));
    }

    public function test_null_and_empty_are_passed_through(): void
    {
        $this->assertNull((new PiiMasker)->mask(null));
        $this->assertSame('', (new PiiMasker)->mask(''));
    }
}
