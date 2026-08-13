<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Idempotent: the admin user is created via firstOrCreate with
     * ADMIN_EMAIL/ADMIN_PASSWORD (see .env.example). Re-running the seeder
     * (e.g. `db:seed --force` in scripts/e2e-up.sh) must not fail.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => (string) env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => 'Admin',
                'password' => (string) env('ADMIN_PASSWORD', 'admin'),
                'email_verified_at' => now(),
            ],
        );
    }
}
