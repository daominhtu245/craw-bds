<?php

namespace Database\Seeders;

use App\Enums\KeywordKind;
use App\Models\Keyword;
use Illuminate\Database\Seeder;

/**
 * Keywords from SPEC 20.2 and 20.3.
 *
 * Weight 2 marks a "strong" positive keyword: an explicit statement of demand.
 * Prefilter step 6 lets an item through despite negative keywords when it has
 * one (threshold: leadradar.prefilter.strong_positive_weight).
 */
class KeywordSeeder extends Seeder
{
    private const POSITIVE = [
        'en' => [
            'looking for' => 2, 'searching for' => 2, 'need a place' => 2, 'need an apartment' => 2,
            'need a house' => 2, 'long term' => 1, 'long-term' => 1, 'monthly rent' => 1,
            'moving to da nang' => 2, 'relocating' => 2, 'arriving in' => 1, 'any recommendations' => 1,
            'recommend an agent' => 1, 'studio' => 1, '1br' => 1, '2br' => 1, 'one bedroom' => 1,
            'two bedroom' => 1, 'pet friendly' => 1, 'where to rent' => 2,
        ],
        'ru' => [
            'ищу' => 2, 'ищем' => 2, 'сниму' => 2, 'снимем' => 2, 'нужна квартира' => 2, 'нужен дом' => 2,
            'долгосрок' => 1, 'на длительный срок' => 1, 'на полгода' => 1, 'на год' => 1,
            'переезжаем' => 2, 'переезжаю' => 2, 'посоветуйте' => 1, 'подскажите' => 1,
            'студию' => 1, 'однушку' => 1, 'двушку' => 1, 'с животными' => 1,
        ],
    ];

    private const NEGATIVE = [
        'en' => [
            'for rent', 'available now', 'available from', 'contact me', 'dm for price', 'whatsapp',
            'viewing anytime', 'price:', '/month only', 'booking now',
        ],
        'ru' => [
            'сдаю', 'сдам', 'сдается', 'свободна', 'свободен', 'пишите в лс', 'цена:', 'бронируйте', 'звоните',
        ],
    ];

    public function run(): void
    {
        foreach (self::POSITIVE as $language => $terms) {
            foreach ($terms as $term => $weight) {
                $this->upsert($language, KeywordKind::Positive, $term, $weight);
            }
        }

        foreach (self::NEGATIVE as $language => $terms) {
            foreach ($terms as $term) {
                $this->upsert($language, KeywordKind::Negative, $term, 1);
            }
        }
    }

    private function upsert(string $language, KeywordKind $kind, string $term, int $weight): void
    {
        Keyword::query()->updateOrCreate(
            ['language' => $language, 'kind' => $kind->value, 'term' => $term],
            ['weight' => $weight, 'is_regex' => false, 'is_active' => true],
        );
    }
}
