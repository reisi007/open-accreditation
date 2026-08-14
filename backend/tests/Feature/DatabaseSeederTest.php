<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_admin_from_env_defaults(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);

        $user = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('admin', $user->password));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_seeder_backfills_email_verified_at_for_pre_p1b_admin(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Admin', 'password' => Hash::make('admin')],
        );

        $this->assertNull(User::where('email', 'admin@example.com')->firstOrFail()->email_verified_at);

        $this->seed(DatabaseSeeder::class);

        $this->assertNotNull(User::where('email', 'admin@example.com')->firstOrFail()->email_verified_at);
    }

    public function test_seeder_uses_env_credentials_when_set(): void
    {
        $previousEnvEmail = $_ENV['ADMIN_EMAIL'] ?? null;
        $previousEnvPassword = $_ENV['ADMIN_PASSWORD'] ?? null;
        $previousServerEmail = $_SERVER['ADMIN_EMAIL'] ?? null;
        $previousServerPassword = $_SERVER['ADMIN_PASSWORD'] ?? null;

        try {
            // env() reads via the dotenv repository; the reader chain checks
            // $_SERVER before $_ENV, and phpdotenv populated both from .env.
            $_SERVER['ADMIN_EMAIL'] = $_ENV['ADMIN_EMAIL'] = 'ci-admin@example.com';
            $_SERVER['ADMIN_PASSWORD'] = $_ENV['ADMIN_PASSWORD'] = 'ci-secret';

            $this->seed(DatabaseSeeder::class);

            $this->assertDatabaseHas('users', ['email' => 'ci-admin@example.com']);

            $user = User::where('email', 'ci-admin@example.com')->firstOrFail();
            $this->assertTrue(Hash::check('ci-secret', $user->password));
        } finally {
            foreach (['ADMIN_EMAIL' => $previousEnvEmail, 'ADMIN_PASSWORD' => $previousEnvPassword] as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
            foreach (['ADMIN_EMAIL' => $previousServerEmail, 'ADMIN_PASSWORD' => $previousServerPassword] as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    /* ---------------------------------------------------------------------
     | P0-Fix-F3: never create a well-known default admin in production.
     | In production the seeder requires BOTH ADMIN_EMAIL and ADMIN_PASSWORD
     | env vars and refuses the default `admin` password; otherwise the admin
     | creation is skipped. Local/testing keep the current behavior.
     | ------------------------------------------------------------------- */

    public function test_production_seeder_skips_default_admin_without_env_credentials(): void
    {
        app()->detectEnvironment(fn () => 'production');

        // .env is loaded in tests, so ADMIN_* may live in $_ENV, $_SERVER AND
        // the process environment (getenv) — clear all three sources so the
        // production seeder sees "no credentials configured".
        $envEmail = getenv('ADMIN_EMAIL');
        $envPassword = getenv('ADMIN_PASSWORD');
        $serverEmail = $_SERVER['ADMIN_EMAIL'] ?? null;
        $serverPassword = $_SERVER['ADMIN_PASSWORD'] ?? null;
        $superglobalEmail = $_ENV['ADMIN_EMAIL'] ?? null;
        $superglobalPassword = $_ENV['ADMIN_PASSWORD'] ?? null;

        try {
            unset($_ENV['ADMIN_EMAIL'], $_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_EMAIL'], $_SERVER['ADMIN_PASSWORD']);
            putenv('ADMIN_EMAIL');
            putenv('ADMIN_PASSWORD');

            // Run the seeder class directly — `$this->seed()` would go through
            // the artisan `db:seed` command, which prompts for confirmation in
            // production (a Mockery/console artifact in tests).
            app(DatabaseSeeder::class)->run();

            $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
            $this->assertDatabaseCount('users', 0);
        } finally {
            $this->restoreEnv('ADMIN_EMAIL', $envEmail, $serverEmail, $superglobalEmail);
            $this->restoreEnv('ADMIN_PASSWORD', $envPassword, $serverPassword, $superglobalPassword);
        }
    }

    /**
     * Restore an env var across the three sources (`getenv`, $_SERVER, $_ENV)
     * to its previous value, or remove it if it was absent before.
     */
    private function restoreEnv(string $key, string|false $processValue, ?string $serverValue, mixed $superglobalValue): void
    {
        if ($processValue === false) {
            putenv($key);
        } else {
            putenv($key.'='.$processValue);
        }

        if ($serverValue === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $serverValue;
        }

        if ($superglobalValue === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $superglobalValue;
        }
    }

    public function test_production_seeder_creates_admin_from_explicit_env_credentials(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $previousEnvEmail = $_ENV['ADMIN_EMAIL'] ?? null;
        $previousEnvPassword = $_ENV['ADMIN_PASSWORD'] ?? null;
        $previousServerEmail = $_SERVER['ADMIN_EMAIL'] ?? null;
        $previousServerPassword = $_SERVER['ADMIN_PASSWORD'] ?? null;

        try {
            $_SERVER['ADMIN_EMAIL'] = $_ENV['ADMIN_EMAIL'] = 'prod-admin@example.com';
            $_SERVER['ADMIN_PASSWORD'] = $_ENV['ADMIN_PASSWORD'] = 'prod-secret-123';

            app(DatabaseSeeder::class)->run();

            $this->assertDatabaseHas('users', ['email' => 'prod-admin@example.com']);
            $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);

            $user = User::where('email', 'prod-admin@example.com')->firstOrFail();
            $this->assertTrue(Hash::check('prod-secret-123', $user->password));
        } finally {
            foreach (['ADMIN_EMAIL' => $previousEnvEmail, 'ADMIN_PASSWORD' => $previousEnvPassword] as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
            foreach (['ADMIN_EMAIL' => $previousServerEmail, 'ADMIN_PASSWORD' => $previousServerPassword] as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    public function test_production_seeder_fails_hard_on_the_default_password(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $previousEnvEmail = $_ENV['ADMIN_EMAIL'] ?? null;
        $previousEnvPassword = $_ENV['ADMIN_PASSWORD'] ?? null;
        $previousServerEmail = $_SERVER['ADMIN_EMAIL'] ?? null;
        $previousServerPassword = $_SERVER['ADMIN_PASSWORD'] ?? null;

        try {
            $_SERVER['ADMIN_EMAIL'] = $_ENV['ADMIN_EMAIL'] = 'prod-admin@example.com';
            $_SERVER['ADMIN_PASSWORD'] = $_ENV['ADMIN_PASSWORD'] = 'admin';

            $this->expectException(RuntimeException::class);

            app(DatabaseSeeder::class)->run();
        } finally {
            foreach (['ADMIN_EMAIL' => $previousEnvEmail, 'ADMIN_PASSWORD' => $previousEnvPassword] as $key => $value) {
                if ($value === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $value;
                }
            }
            foreach (['ADMIN_EMAIL' => $previousServerEmail, 'ADMIN_PASSWORD' => $previousServerPassword] as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
}
