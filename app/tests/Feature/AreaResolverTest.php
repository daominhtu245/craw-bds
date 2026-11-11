<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Services\Areas\AreaResolver;
use Database\Seeders\AreaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AreaResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AreaSeeder::class);
    }

    public static function cases(): array
    {
        return [
            'english' => [['An Thuong'], ['an-thuong']],
            'vietnamese diacritics' => [['Mỹ Khê'], ['my-khe']],
            'no diacritics uppercase' => [['SON TRA'], ['son-tra']],
            'russian alias' => [['Ан Тхыонг'], ['an-thuong']],
            'russian alias with й' => [['Хойан'], ['hoi-an']],
            'russian two-word alias' => [['в районе Хай Чау'], ['hai-chau']],
            'nickname' => [['Marble Mountains'], ['ngu-hanh-son']],
            'phrase containing alias' => [['near My Khe beach area'], ['my-khe']],
            'slug' => [['fpt-city'], ['fpt-city']],
            'dedupe and order' => [['Son Tra', 'An Thuong', 'sơn trà'], ['son-tra', 'an-thuong']],
            'unknown' => [['Bangkok'], []],
        ];
    }

    #[DataProvider('cases')]
    public function test_resolve(array $mentioned, array $expected): void
    {
        $this->assertSame($expected, app(AreaResolver::class)->resolve($mentioned));
    }

    public function test_unmatched_area_is_logged(): void
    {
        Log::spy();
        app(AreaResolver::class)->resolve(['Nowhere Town']);

        Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $msg === 'area.unmatched' && $ctx['area'] === 'Nowhere Town');
    }

    public function test_new_alias_is_used_after_save(): void
    {
        $resolver = app(AreaResolver::class);
        $this->assertSame([], $resolver->resolve(['Golden Pine']));

        $area = Area::firstWhere('slug', 'son-tra');
        $area->update(['aliases' => [...$area->aliases, 'golden pine']]);

        $this->assertSame(['son-tra'], $resolver->resolve(['Golden Pine']));
    }
}
