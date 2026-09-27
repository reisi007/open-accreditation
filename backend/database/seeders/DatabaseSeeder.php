<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
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
        $this->seedDomain($main, 'localhost');

        // Primary mandant is served via Laravel Herd under
        // https://accreditation.test (plus its www alias). firstOrCreate matches
        // on hostname alone, so re-seeding an existing dev DB retroactively adds
        // these domains to a `main` mandant that predates them.
        $this->seedDomain($main, 'accreditation.test');
        $this->seedDomain($main, 'www.accreditation.test');

        $bundesliga = Mandant::firstOrCreate(
            ['slug' => 'bundesliga'],
            [
                'name' => 'Bundesliga',
                'teams_enabled' => false,
                'is_active' => true,
            ],
        );

        $this->seedDomain($bundesliga, 'bundesliga.test');
        $this->seedDomain($bundesliga, 'www.bundesliga.test');

        // Keep the primary flag consistent even if a firstOrCreate matched an
        // existing mandant row that lost its primary flag meanwhile.
        $main->update(['is_primary' => true]);
    }

    /**
     * Write a mandant hostname, then drop the caches that would keep it
     * unreachable.
     *
     * The seeder is the one hostname-writing path outside the controllers, and
     * `features/02-domain-model.md` now mandates the invalidation for EVERY
     * such path. Without it, a re-seed next to a running server (`db:seed
     * --force`, `scripts/e2e-up.sh`) leaves a freshly added host answering 400
     * for up to `mandants.cache_ttl` (3600 s): the `trustHosts` allow-list is
     * cached as a whole list (`MandantContext::hostnames()`), and a host that
     * is not on that list is rejected before any mandant lookup happens.
     * Symmetrically, a host that was already *looked up* and missed sits in the
     * negative cache as `MISSING` for `NEGATIVE_CACHE_TTL_SECONDS` and would
     * keep resolving to no mandant at all.
     *
     * Both helpers are plain `Cache::forget()` calls on static cache keys —
     * they need no request-bound mandant context, so this stays a no-op-safe
     * call in a console/seed context (a `Cache::forget` on a key that was
     * never written is a no-op, and `hostnames()`' own "database not
     * available → return null" path is untouched).
     */
    private function seedDomain(Mandant $mandant, string $hostname): void
    {
        $mandant->domains()->firstOrCreate(['hostname' => $hostname]);

        // Same pair, same order, as `MandantDomainController::store()` /
        // `::destroy()` and `MandantController::update()` / `::destroy()`.
        MandantContext::forgetHost($hostname);
        MandantContext::forgetHostnames();
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
