<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Idempotent: admin user, roles, role assignments and mandants are created
     * via firstOrCreate, so re-running the seeder (e.g. `db:seed --force` in
     * scripts/e2e-up.sh) must not fail or duplicate rows.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $admin = User::firstOrCreate(
            ['email' => (string) env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => 'Admin',
                'password' => (string) env('ADMIN_PASSWORD', 'admin'),
                'email_verified_at' => now(),
            ],
        );

        // B6 backfill: pre-P1b seeders could not set email_verified_at (it was
        // not fillable), so an existing admin row may still be unverified.
        if ($admin->email_verified_at === null) {
            $admin->update(['email_verified_at' => now()]);
        }

        // The bootstrap admin is the global super admin (mandant_id = null).
        RoleUser::firstOrCreate([
            'user_id' => $admin->id,
            'role_id' => Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->value('id'),
            'mandant_id' => null,
            'team_id' => null,
        ]);

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
