<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * All seeders are idempotent: running `migrate --seed` twice is safe.
     */
    public function run(): void
    {
        $this->call([
            AreaSeeder::class,
            KeywordSeeder::class,
            SettingSeeder::class,
            AdminUserSeeder::class,
            ListingSeeder::class,
        ]);
    }
}
