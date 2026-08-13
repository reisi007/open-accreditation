<?php

namespace Database\Seeders;

use App\Models\Mandant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Idempotent: admin user and mandants are created via firstOrCreate, so
     * re-running the seeder (e.g. `db:seed --force` in scripts/e2e-up.sh)
     * must not fail or duplicate rows.
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

        $main = Mandant::firstOrCreate(
            ['slug' => 'main'],
            [
                'name' => 'Hauptseite',
                'teams_enabled' => false,
                'is_primary' => true,
                'is_active' => true,
            ],
        );

        // The local dev/E2E flow reaches the backend via http://localhost:8000
        // (scripts/e2e-up.sh, Vite proxy), so `localhost` must resolve to the
        // primary mandant or the MandantContextMiddleware would 404 it.
        $main->domains()->firstOrCreate(['hostname' => 'localhost']);

        // Primary mandant is served via Laravel Herd under
        // https://accreditation.test (plus its www alias). firstOrCreate matches
        // on hostname alone, so re-seeding an existing dev DB retroactively adds
        // these domains to a `main` mandant that predates them.
        $main->domains()->firstOrCreate(['hostname' => 'accreditation.test']);
        $main->domains()->firstOrCreate(['hostname' => 'www.accreditation.test']);

        $bundesliga = Mandant::firstOrCreate(
            ['slug' => 'bundesliga'],
            [
                'name' => 'Bundesliga',
                'teams_enabled' => false,
                'is_active' => true,
            ],
        );

        $bundesliga->domains()->firstOrCreate(['hostname' => 'bundesliga.test']);
        $bundesliga->domains()->firstOrCreate(['hostname' => 'www.bundesliga.test']);

        // Keep the primary flag consistent even if a firstOrCreate matched an
        // existing mandant row that lost its primary flag meanwhile.
        $main->update(['is_primary' => true]);
    }
}
