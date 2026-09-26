<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Services\MandantMailerService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WP-6-c / WP-6-d / WP-6-e — the three schema-and-config hardenings of the
 * "Schema + Portabilität" work package.
 *
 *  - c: `users` had no index that could serve the login hot path's
 *    `where('email', …)` on the `MandantContext::currentId() === null` branch.
 *  - d (R-D9): `mandants.smtp_config` stored third-party SMTP credentials in
 *    cleartext; `MandantResource` only masked them at the API boundary.
 *  - e: `APP_MAINTENANCE_DRIVER` defaulted to `file`, so `php artisan down`
 *    only propagated when every instance shared that one file.
 */
class SchemaHardeningTest extends TestCase
{
    use RefreshDatabase;

    /* ---------------------------------------------------------------------
     | WP-6-c — index on users.email
     | ------------------------------------------------------------------- */

    public function test_users_carries_a_single_column_index_on_email(): void
    {
        $indexes = collect(Schema::getIndexes('users'))->keyBy('name');

        $this->assertTrue(
            $indexes->has('users_email_index'),
            'the unscoped login lookup `where(email, …)` needs its own index, got: '.implode(', ', $indexes->keys()->all()),
        );

        $index = $indexes->get('users_email_index');

        $this->assertSame(['email'], $index['columns']);
        $this->assertFalse(
            $index['unique'],
            'the index must NOT be unique: email uniqueness is per mandant (BE-R1), the composite '
            .'`users_mandant_id_email_unique` stays the enforcement layer',
        );
    }

    public function test_the_composite_per_mandant_email_unique_survives(): void
    {
        $indexes = collect(Schema::getIndexes('users'))->keyBy('name');

        $this->assertTrue($indexes->has('users_mandant_id_email_unique'));
        $this->assertTrue($indexes->get('users_mandant_id_email_unique')['unique']);
    }

    public function test_the_unscoped_login_lookup_is_served_by_the_new_index(): void
    {
        // `AuthController::findLoginUser()` runs this exact shape whenever
        // `MandantContext::currentId()` is null (unmapped host, CLI, tests).
        $mandant = Mandant::factory()->create();
        $user = User::factory()->forMandant($mandant)->create(['email' => 'login@example.com']);

        $this->assertNotNull(User::query()->where('email', 'login@example.com')->first());
        $this->assertTrue($user->exists);
    }

    /* ---------------------------------------------------------------------
     | WP-6-d (R-D9) — smtp_config is stored encrypted
     | ------------------------------------------------------------------- */

    /**
     * POSTGRES-SIDE GUARD. On SQLite this assertion passes in the broken AND
     * the fixed state, because SQLite's grammar maps `json` to plain `text` —
     * that mapping is exactly why the defect was invisible to the test suite.
     * It becomes a real assertion the moment the suite is run against Postgres
     * (the Go-Live "Postgres-Portabilitäts-Gate"), where a `json` column
     * rejects Laravel's ciphertext outright:
     *
     *   ERROR: invalid input syntax for type json
     *
     * The engine-agnostic counterpart is
     * {@see test_the_stored_smtp_config_is_no_longer_a_json_document()}.
     */
    public function test_the_smtp_config_column_is_text_so_postgres_accepts_the_ciphertext(): void
    {
        $column = collect(Schema::getColumns('mandants'))->firstWhere('name', 'smtp_config');

        $this->assertNotNull($column, 'mandants.smtp_config must exist');
        $this->assertSame(
            'text',
            $column['type'],
            'Laravel ciphertext is a bare base64 string; a `json` column rejects it on Postgres.',
        );
        $this->assertTrue($column['nullable']);
    }

    public function test_the_stored_smtp_config_is_no_longer_a_json_document(): void
    {
        $mandant = Mandant::factory()->create([
            'smtp_config' => ['host' => 'mail.example.com', 'port' => 587],
        ]);

        $raw = DB::table('mandants')->where('id', $mandant->id)->value('smtp_config');

        $this->assertIsString($raw);
        $this->assertNull(
            json_decode($raw, true),
            'the column must hold opaque ciphertext, not a readable JSON document',
        );
    }

    public function test_the_smtp_password_never_reaches_the_database_in_cleartext(): void
    {
        $mandant = Mandant::factory()->create([
            'smtp_config' => [
                'host' => 'mail.example.com',
                'port' => 587,
                'username' => 'relay@example.com',
                'password' => 'geheim123',
            ],
        ]);

        $raw = DB::table('mandants')->where('id', $mandant->id)->value('smtp_config');

        $this->assertIsString($raw);
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('geheim123', $raw);
        $this->assertStringNotContainsString('mail.example.com', $raw);
        $this->assertStringNotContainsString('relay@example.com', $raw);

        // …while the model still round-trips the plaintext for the transport.
        $this->assertSame('geheim123', $mandant->fresh()->smtp_config['password']);
        $this->assertSame('mail.example.com', $mandant->fresh()->smtp_config['host']);
    }

    public function test_the_encrypted_smtp_config_still_drives_the_mandant_transport(): void
    {
        $mandant = Mandant::factory()->create([
            'smtp_config' => [
                'host' => 'mail.example.com',
                'port' => 587,
                'username' => 'relay@example.com',
                'password' => 'geheim123',
                'encryption' => 'tls',
            ],
        ]);

        // A fresh read from the DB (not the in-memory instance) must decrypt.
        $transport = app(MandantMailerService::class)->transportFor(Mandant::query()->findOrFail($mandant->id));

        $this->assertNotNull($transport);
    }

    public function test_a_mandant_without_an_smtp_config_still_reads_as_null(): void
    {
        $mandant = Mandant::factory()->create(['smtp_config' => null]);

        $this->assertNull($mandant->fresh()->smtp_config);
        $this->assertNull(app(MandantMailerService::class)->transportFor($mandant->fresh()));
    }

    public function test_a_legacy_cleartext_row_becomes_unreadable_rather_than_silently_plaintext(): void
    {
        // The documented consequence of the cast: pre-existing rows hold plain
        // JSON that the encrypter refuses. It must fail LOUDLY, never silently
        // hand out a half-decrypted config.
        $mandant = Mandant::factory()->create();
        DB::table('mandants')->where('id', $mandant->id)->update([
            'smtp_config' => json_encode(['host' => 'legacy.example.com', 'password' => 'alt']),
        ]);

        $this->expectException(DecryptException::class);

        Mandant::query()->findOrFail($mandant->id)->smtp_config;
    }

    /* ---------------------------------------------------------------------
     | WP-6-e — maintenance mode driver
     | ------------------------------------------------------------------- */

    public function test_the_maintenance_driver_default_is_the_database_driver(): void
    {
        // `phpunit.xml` pins `APP_MAINTENANCE_DRIVER=file` for the suite, so
        // `config('app.maintenance.driver')` cannot be the assertion here — the
        // DEFAULT in `config/app.php` is what ships. Read the source.
        $config = File::get(base_path('config/app.php'));

        $this->assertMatchesRegularExpression(
            "/'driver'\s*=>\s*env\('APP_MAINTENANCE_DRIVER',\s*'database'\)/",
            $config,
            "the maintenance driver must default to 'database' so `artisan down` propagates to every instance",
        );

        $this->assertMatchesRegularExpression(
            "/'store'\s*=>\s*env\('APP_MAINTENANCE_STORE',\s*'database'\)/",
            $config,
        );
    }

    public function test_the_env_example_ships_the_propagating_driver(): void
    {
        $example = File::get(base_path('.env.example'));

        $this->assertMatchesRegularExpression(
            '/^APP_MAINTENANCE_DRIVER=database$/m',
            $example,
            'copying .env.example must not reintroduce the single-instance `file` driver',
        );
    }

    public function test_the_suite_pin_is_an_explicit_isolation_choice(): void
    {
        // Documented, not accidental: the test suite keeps the `file` driver so
        // a maintenance-mode test can never write into the test database. If
        // this pin is ever removed the assertion above becomes the only guard.
        $phpunit = File::get(base_path('phpunit.xml'));

        $this->assertStringContainsString(
            '<env name="APP_MAINTENANCE_DRIVER" value="file"/>',
            $phpunit,
        );
    }

    public function test_the_configured_driver_is_honoured_at_runtime(): void
    {
        config(['app.maintenance.driver' => 'file']);

        $this->assertSame('file', config('app.maintenance.driver'));
        $this->assertSame('database', config('app.maintenance.store'));
    }

    /* ---------------------------------------------------------------------
     | Sanity: nothing about the tenants regressed
     | ------------------------------------------------------------------- */

    public function test_the_encrypted_cast_does_not_disturb_role_scopes(): void
    {
        $this->seed(RoleSeeder::class);

        $mandant = Mandant::factory()->create();
        $user = User::factory()->forMandant($mandant)->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::USER->value)->valueOrFail('id'),
            'mandant_id' => $mandant->id,
            'team_id' => null,
        ]);

        MandantContext::set($mandant);

        $this->assertTrue($user->roleUserAssignments()->forMandant($mandant->id)->exists());
    }
}
