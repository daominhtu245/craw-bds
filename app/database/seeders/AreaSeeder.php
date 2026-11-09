<?php

namespace Database\Seeders;

use App\Models\Area;
use Illuminate\Database\Seeder;

/**
 * Expat-style area names (SPEC 20.1), not official administrative units.
 */
class AreaSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            ['an-thuong', 'An Thuong', 'An Thượng', ['an thượng', 'an thuong', 'expat street', 'ан тхыонг', 'антхыонг']],
            ['my-khe', 'My Khe', 'Mỹ Khê', ['mỹ khê', 'my khe', 'my khe beach', 'ми кхе', 'микхе']],
            ['son-tra', 'Son Tra', 'Sơn Trà', ['sơn trà', 'son tra', 'son tra peninsula', 'шонча', 'сон тра']],
            ['ngu-hanh-son', 'Ngu Hanh Son', 'Ngũ Hành Sơn', ['ngũ hành sơn', 'ngu hanh son', 'marble mountains', 'нгуханьшон', 'мраморные горы']],
            ['hai-chau', 'Hai Chau', 'Hải Châu', ['hải châu', 'hai chau', 'city center', 'downtown', 'han river', 'хайчау', 'центр']],
            ['thanh-khe', 'Thanh Khe', 'Thanh Khê', ['thanh khê', 'thanh khe', 'тхань кхе']],
            ['lien-chieu', 'Lien Chieu', 'Liên Chiểu', ['liên chiểu', 'lien chieu', 'nam o', 'льен тьеу']],
            ['hoa-xuan', 'Hoa Xuan', 'Hòa Xuân', ['hòa xuân', 'hoa xuan', 'хоа суан']],
            ['fpt-city', 'FPT City', 'FPT City', ['fpt city', 'фпт сити']],
            ['non-nuoc', 'Non Nuoc', 'Non Nước', ['non nước', 'non nuoc', 'non nuoc beach']],
            ['hoi-an', 'Hoi An', 'Hội An', ['hội an', 'hoi an', 'an bang', 'хойан']],
        ];

        foreach ($areas as [$slug, $nameEn, $nameVi, $aliases]) {
            Area::query()->updateOrCreate(
                ['slug' => $slug],
                ['name_en' => $nameEn, 'name_vi' => $nameVi, 'aliases' => $aliases],
            );
        }
    }
}
