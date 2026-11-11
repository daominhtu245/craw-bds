<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Area;
use App\Models\Keyword;
use App\Models\Listing;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_is_complete_and_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(11, Area::count());
        $this->assertContains('ан тхыонг', Area::firstWhere('slug', 'an-thuong')->aliases);
        $this->assertSame(80, Keyword::where('kind', 'positive')->count());
        $this->assertSame(60, Keyword::where('kind', 'negative')->count());
        $this->assertSame(2, Keyword::firstWhere('term', 'ищу')->weight);
        // Vietnamese terms are seeded with and without diacritics.
        $this->assertSame(2, Keyword::where('kind', 'positive')->firstWhere('term', 'can thue')->weight);
        $this->assertSame(5, Listing::count());
        $this->assertSame(26000, Setting::get('fx_per_usd')['VND']);
        $this->assertSame(1, User::where('role', UserRole::Admin->value)->count());
    }

    public function test_reseeding_keeps_settings_changed_by_admin(): void
    {
        $this->seed();
        Setting::put('notify_min_score', 75);
        $this->seed();

        $this->assertSame(75, Setting::get('notify_min_score'));
    }
}
