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

    /**
     * Keyword classifier (used when AI is off). kind => language => term => weight.
     * The intent with the highest total weight wins; see RuleClassifier.
     */
    private const CLASSIFIER = [
        'intent_tenant' => [
            'en' => [
                'looking for' => 2, 'searching for' => 2, 'need a place' => 2, 'need an apartment' => 2, 'need a house' => 2,
                'need a' => 1, 'want to rent' => 2, 'where to rent' => 2, 'any leads on' => 2, 'can anyone recommend' => 1,
                'recommend an agent' => 1, 'thinking about renting' => 1, 'long term' => 1, 'long-term' => 1,
                'want to find' => 2, 'where can i find' => 2, 'does anyone have' => 2, 'anyone with a' => 2,
                'interested in' => 1, 'need any' => 1, 'needs any' => 1, 'needs a' => 1, 'renting a' => 1, 'to find' => 1,
            ],
            'ru' => [
                'ищу' => 2, 'ищем' => 2, 'сниму' => 2, 'снимем' => 2, 'хотим снять' => 2, 'нужна квартира' => 2,
                'нужен дом' => 2, 'нужно жильё' => 2, 'нужно жилье' => 2, 'снять' => 1, 'снимать' => 1,
                'планируем переезд' => 1, 'думаем о переезде' => 1,
                'хотим арендовать' => 2, 'арендовать' => 1, 'хотим найти' => 2, 'найти квартиру' => 2, 'кто сдает' => 2,
                'нужна' => 1, 'нужен' => 1,
            ],
        ],
        'intent_handover' => [
            'en' => [
                'take over my lease' => 4, 'take over our' => 4, 'someone to take over' => 4, 'takeover' => 3,
                'lease transfer' => 3, 'transfer the lease' => 3, 'leaving vietnam' => 2, 'deposit can be transferred' => 2,
                'pass my contract' => 4, 'continue my rental' => 4, 'continue my lease' => 4, 'take over the lease' => 4,
                'contract to someone' => 3, 'leaving da nang' => 2, 'going back to' => 1,
            ],
            'ru' => [
                'передать договор' => 4, 'передам квартиру' => 4, 'переоформить договор' => 3, 'кому передать' => 3,
                'продолжит аренду' => 4, 'переуступ' => 4, 'возвращаемся' => 1, 'уезжаем' => 1,
            ],
        ],
        'intent_landlord' => [
            'en' => [
                'for rent' => 1, 'renting out' => 3, 'available now' => 2, 'available from' => 2, 'room available' => 2,
                'viewing anytime' => 2, 'for viewing' => 2, 'looking for a tenant' => 4, 'long-term tenant' => 3,
                'will be free from' => 2, 'contact me for' => 2, 'minimum 6 months' => 1,
                'for long term rent' => 3, 'rent to foreigners' => 3, 'free from' => 2, 'no agents' => 2,
                'for more photos' => 2, 'including management fee' => 2,
            ],
            'ru' => ['сдаю' => 3, 'сдам' => 3, 'сдается' => 3, 'сдаётся' => 3, 'свободна с' => 2, 'свободен с' => 2, 'пишите в лс' => 1, 'пишите в личку' => 1],
        ],
        'intent_agent' => [
            'en' => [
                'agency' => 3, 'real estate agent' => 3, 'local agent' => 3, 'no fee for tenants' => 3, 'new listings' => 3,
                'property management' => 3, 'i help expats find' => 3, 'whatsapp for details' => 2, 'free viewing' => 2,
                'units available' => 2, 'our company' => 2, 'manages more than' => 3, 'book a free' => 2,
                'i can help you find' => 3, 'many options' => 2, 'check your inbox' => 2,
            ],
            'ru' => [
                'агентство' => 3, 'риелтор' => 3, 'риэлтор' => 3, 'без комиссии' => 3, 'подберём' => 3, 'подберем' => 3, 'большая база' => 3,
                'помогаем с арендой' => 3, 'подберу' => 3, 'комиссию платит' => 3,
            ],
        ],
        'intent_short_term' => [
            'en' => ['hotel' => 2, 'airbnb' => 1, 'homestay' => 2, 'short stay' => 3, 'short-term' => 2, 'for the holidays' => 2],
            'ru' => ['на праздники' => 2, 'посуточно' => 3, 'бронировать' => 1, 'отель' => 2],
        ],
        'intent_other_city' => [
            'en' => [
                'ho chi minh' => 3, 'saigon' => 3, 'hcmc' => 3, 'hanoi' => 3, 'ha noi' => 3, 'nha trang' => 3,
                'quy nhon' => 3, 'phu quoc' => 3, 'da lat' => 3, 'dalat' => 3, 'vung tau' => 3,
            ],
            'ru' => ['хошимин' => 3, 'сайгон' => 3, 'ханой' => 3, 'нячанг' => 3, 'фукуок' => 3, 'далат' => 3, 'куинён' => 3],
        ],
        'stage_urgent' => [
            'en' => ['urgent' => 1, 'asap' => 1, 'this friday' => 1, 'this weekend' => 1, 'this week' => 1, 'tomorrow' => 1, 'move out' => 1, 'right away' => 1, 'have to leave' => 1, 'cancelled' => 1],
            'ru' => ['срочно' => 1, 'с завтрашнего дня' => 1, 'на этой неделе' => 1, 'съезжаем' => 1, 'съехать' => 1, 'завтра' => 1],
        ],
        'stage_in_city' => [
            'en' => ['already in da nang' => 1, 'been in da nang' => 1, 'currently in da nang' => 1, 'staying at a hotel' => 1, 'staying in a hotel' => 1, 'already here' => 1, 'right now' => 1, 'currently staying' => 1],
            'ru' => ['уже в дананге' => 1, 'уже нахожусь' => 1, 'уже месяц' => 1, 'уже живём' => 1, 'уже живем' => 1, 'мы уже' => 1, 'уже две недели' => 1, 'в отеле' => 1],
        ],
        'stage_pre_arrival' => [
            'en' => ['moving to' => 1, 'relocating' => 1, 'arriving' => 1, 'we land' => 1, 'land on' => 1, 'will arrive' => 1, 'coming to da nang' => 1, 'coming back' => 1, "i'll be in" => 1, 'will be in da nang' => 1],
            'ru' => ['переезжаем' => 1, 'переезжаю' => 1, 'планируем переезд' => 1, 'прилетаю' => 1, 'прилетаем' => 1, 'приезжаем' => 1, 'приезжаю' => 1, 'приедем' => 1],
        ],
    ];

    public function run(): void
    {
        foreach (self::CLASSIFIER as $kind => $languages) {
            foreach ($languages as $language => $terms) {
                foreach ($terms as $term => $weight) {
                    $this->upsert($language, KeywordKind::from($kind), $term, $weight);
                }
            }
        }

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
