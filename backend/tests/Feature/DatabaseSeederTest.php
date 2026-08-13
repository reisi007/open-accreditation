<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}
