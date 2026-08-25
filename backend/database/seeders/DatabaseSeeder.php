<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Idempotent: admin user, roles, role assignments and mandants are created
     * via firstOrCreate, so re-running the seeder (e.g. `db:seed --force` in
     * scripts/e2e-up.sh) must not fail or duplicate rows.
     *
     * P0-Fix-F3: in `production` the well-known default admin must never be
     * created from the fallback defaults (`admin@example.com` / `admin`).
     * A production seed requires BOTH `ADMIN_EMAIL` and `ADMIN_PASSWORD` to be
     * explicitly set AND a non-default password — otherwise the admin creation
     * is skipped with a loud log (or fails hard on the default password).
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        $admin = $this->resolveAdmin();

        if ($admin !== null) {
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
        }

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

    /**
     * Create or match the bootstrap admin user under the P0-Fix-F3 policy:
     *
     * - non-production: current behavior (env defaults, well-known fallbacks).
     * - production: require BOTH `ADMIN_EMAIL` and `ADMIN_PASSWORD` env vars;
     *   the well-known `admin` password is refused hard. Missing vars → skip
     *   (loud warning), so a production seed never fabricates a default admin.
     */
    private function resolveAdmin(): ?User
    {
        $production = app()->environment('production');
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if ($production) {
            if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
                Log::warning('DatabaseSeeder: skipping bootstrap admin creation in production — set both ADMIN_EMAIL and ADMIN_PASSWORD.');

                return null;
            }

            if ($password === 'admin') {
                throw new RuntimeException(
                    'DatabaseSeeder: refusing to create the bootstrap admin with the default password "admin" in production. Set ADMIN_PASSWORD to a strong, unique value.',
                );
            }
        }

        return User::firstOrCreate(
            [
                'email' => (string) ($email ?? 'admin@example.com'),
                // BE-R1: emails are unique per mandant — the bootstrap admin
                // is the GLOBAL account (mandant_id null, matching its global
                // super_admin role_user row below). Scoping the match prevents
                // attaching the super_admin role to a mandant-scoped account
                // that happens to share the same address.
                'mandant_id' => null,
            ],
            [
                'name' => 'Admin',
                'password' => (string) ($password ?? 'admin'),
                'email_verified_at' => now(),
            ],
        );
    }
}
