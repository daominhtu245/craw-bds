<?php

namespace Database\Seeders;

use App\Enums\PropertyType;
use App\Models\Listing;
use Illuminate\Database\Seeder;

/**
 * Five made-up sample listings for development, matching tests and the demo public site.
 * Texts are self-written demo content, not real apartments.
 */
class ListingSeeder extends Seeder
{
    /** Demo texts for the public site: size m², then VI/EN/RU title and description. */
    private const DEMO_CONTENT = [
        'Sea Breeze 1BR' => [45, [
            'vi' => ['title' => 'Căn 1 phòng ngủ gần biển An Thượng', 'description' => 'Căn hộ 1 phòng ngủ sáng, có bếp riêng, góc làm việc và wifi nhanh. Đi bộ 5 phút ra biển.'],
            'en' => ['title' => 'Sea Breeze 1BR near An Thuong beach', 'description' => 'Bright one-bedroom with its own kitchen, a work corner and fast Wi-Fi. A 5-minute walk to the beach.'],
            'ru' => ['title' => '1-комнатная Sea Breeze у пляжа Ан Тхыонг', 'description' => 'Светлая квартира с одной спальней, кухней, рабочим местом и быстрым Wi-Fi. 5 минут пешком до пляжа.'],
        ]],
        'Han River View 2BR' => [70, [
            'vi' => ['title' => 'Căn 2 phòng ngủ view sông Hàn', 'description' => 'Căn 2 phòng ngủ tầng cao, ban công nhìn ra sông Hàn, có hồ bơi và phòng gym trong tòa nhà.'],
            'en' => ['title' => 'Han River View 2BR', 'description' => 'High-floor two-bedroom with a balcony over the Han River. Pool and gym in the building.'],
            'ru' => ['title' => '2 спальни с видом на реку Хан', 'description' => 'Квартира с двумя спальнями на высоком этаже, балкон с видом на реку Хан. В доме бассейн и спортзал.'],
        ]],
        'My Khe Cozy Studio' => [32, [
            'vi' => ['title' => 'Studio ấm cúng gần biển Mỹ Khê', 'description' => 'Studio nhỏ gọn, đủ nội thất, phù hợp một người hoặc cặp đôi. Thuê từ 1 tháng.'],
            'en' => ['title' => 'My Khe Cozy Studio', 'description' => 'Compact furnished studio for one person or a couple. Rent from one month.'],
            'ru' => ['title' => 'Уютная студия у пляжа Ми Кхе', 'description' => 'Компактная студия с мебелью для одного человека или пары. Аренда от месяца.'],
        ]],
        'Son Tra Family House 3BR' => [140, [
            'vi' => ['title' => 'Nhà 3 phòng ngủ cho gia đình ở Sơn Trà', 'description' => 'Nhà nguyên căn yên tĩnh, có chỗ để xe và view biển từ tầng trên. Cho nuôi thú cưng.'],
            'en' => ['title' => 'Son Tra Family House 3BR', 'description' => 'Quiet whole house with parking and a sea view from the top floor. Pets allowed.'],
            'ru' => ['title' => 'Семейный дом с 3 спальнями в Сон Тра', 'description' => 'Тихий дом целиком, парковка, вид на море с верхнего этажа. Можно с животными.'],
        ]],
        'Marble Mountains Pool Villa' => [250, [
            'vi' => ['title' => 'Biệt thự hồ bơi gần Ngũ Hành Sơn', 'description' => 'Biệt thự 4 phòng ngủ có hồ bơi riêng, sân vườn, phù hợp gia đình đông người.'],
            'en' => ['title' => 'Marble Mountains Pool Villa', 'description' => 'Four-bedroom villa with a private pool and garden, good for a large family.'],
            'ru' => ['title' => 'Вилла с бассейном у Мраморных гор', 'description' => 'Вилла с четырьмя спальнями, собственным бассейном и садом, для большой семьи.'],
        ]],
    ];

    public function run(): void
    {
        $listings = [
            [
                'title' => 'Sea Breeze 1BR', 'area_slug' => 'an-thuong', 'property_type' => PropertyType::OneBr,
                'bedrooms' => 1, 'price_usd' => 420, 'min_term_months' => 3, 'pet_friendly' => true,
                'features' => ['kitchen', 'workspace', 'fast_wifi', 'washing_machine', 'near_beach'],
            ],
            [
                'title' => 'Han River View 2BR', 'area_slug' => 'hai-chau', 'property_type' => PropertyType::TwoBr,
                'bedrooms' => 2, 'price_usd' => 650, 'min_term_months' => 6, 'pet_friendly' => false,
                'features' => ['river_view', 'pool', 'gym', 'balcony', 'kitchen'],
            ],
            [
                'title' => 'My Khe Cozy Studio', 'area_slug' => 'my-khe', 'property_type' => PropertyType::Studio,
                'bedrooms' => 0, 'price_usd' => 330, 'min_term_months' => 1, 'pet_friendly' => false,
                'features' => ['near_beach', 'fast_wifi', 'kitchen'],
            ],
            [
                'title' => 'Son Tra Family House 3BR', 'area_slug' => 'son-tra', 'property_type' => PropertyType::House,
                'bedrooms' => 3, 'price_usd' => 900, 'min_term_months' => 12, 'pet_friendly' => true,
                'features' => ['family', 'parking', 'quiet', 'sea_view', 'washing_machine', 'kitchen'],
            ],
            [
                'title' => 'Marble Mountains Pool Villa', 'area_slug' => 'ngu-hanh-son', 'property_type' => PropertyType::Villa,
                'bedrooms' => 4, 'price_usd' => 1500, 'min_term_months' => 6, 'pet_friendly' => true,
                'features' => ['pool', 'family', 'parking', 'quiet', 'bathtub', 'kitchen'],
            ],
        ];

        foreach ($listings as $listing) {
            [$size, $content] = self::DEMO_CONTENT[$listing['title']];
            Listing::query()->updateOrCreate(
                ['title' => $listing['title']],
                $listing + [
                    'furnished' => true, 'is_active' => true, 'available_from' => now()->toDateString(),
                    'is_public' => true, 'size_m2' => $size, 'content' => $content,
                ],
            );
        }
    }
}
