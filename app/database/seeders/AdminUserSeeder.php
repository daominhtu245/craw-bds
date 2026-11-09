<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first admin from ADMIN_EMAIL / ADMIN_PASSWORD. When no password
 * is configured a random one is generated and printed once.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('leadradar.admin.email');

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $password = (string) config('leadradar.admin.password');
        $generated = $password === '';
        if ($generated) {
            $password = Str::password(20, symbols: false);
        }

        User::query()->create([
            'name' => 'Admin',
            'email' => $email,
            'password' => $password,
            'role' => UserRole::Admin,
        ]);

        if ($generated && $this->command) {
            $this->command->warn("Admin created: {$email} / {$password} (change it after first login)");
        }
    }
}
