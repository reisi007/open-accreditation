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
use Illuminate\Foundation\ArrayMaintenanceMode;
use Illuminate\Foundation\CacheBasedMaintenanceMode;
use Illuminate\Foundation\FileBasedMaintenanceMode;
use Illuminate\Foundation\MaintenanceModeManager;
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
 *  - e: `APP_MAINTENANCE_DRIVER` was changed to `database` — a driver Laravel 13
 *    does NOT implement. `PreventRequestsDuringMaintenance` is global and calls
 *    `maintenanceMode()->active()` per request, so the unbuildable driver turned
 *    every request into a 500. The suite stayed green because `phpunit.xml` pins
 *    `file`, so nothing ever resolved the SHIPPED default through the container;
 *    these tests now do exactly that.
 */
class SchemaHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // `MandantContext` lives in the CONTAINER, which is rebuilt per test —
        // but an instance left behind by `test_the_encrypted_cast_does_not_
        // disturb_role_scopes` would still be visible to anything that resolves
        // the container before the rebuild, and it made the maintenance-driver
        // request assertion depend on test order. Reset unconditionally.
        MandantContext::reset();

        parent::tearDown();
    }

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
     | F3 — the documented remediation must not throw
     | ------------------------------------------------------------------- */

    /**
     * F3: `features/02-domain-model.md` documents the operator remediation for a
     * pre-encryption row as "a re-save: `PUT /api/admin/mandants/{id}` with the
     * same `smtp_config`". The merge in `MandantController::update()` read
     * `$mandant->smtp_config` for every non-null payload, so on exactly the row
     * the remediation targets it threw `DecryptException` — only
     * `{"smtp_config": null}` (i.e. discarding the credentials) ever worked.
     */
    public function test_resaving_the_same_smtp_config_on_a_legacy_row_works(): void
    {
        $mandant = $this->superAdminMandant();
        $this->writeLegacyCleartextSmtpConfig($mandant, [
            'host' => 'legacy.example.com',
            'port' => 587,
            'username' => 'relay@example.com',
            'password' => 'alt',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$mandant->id, [
                'smtp_config' => [
                    'host' => 'legacy.example.com',
                    'port' => 587,
                    'username' => 'relay@example.com',
                    'password' => 'alt',
                ],
            ])
            ->assertOk();

        // The row is readable again and stored as ciphertext.
        $this->assertSame('legacy.example.com', $mandant->fresh()->smtp_config['host']);
        $this->assertSame('alt', $mandant->fresh()->smtp_config['password']);
        $this->assertNull(
            json_decode((string) DB::table('mandants')->where('id', $mandant->id)->value('smtp_config'), true),
            'the re-saved config must be encrypted, not plain JSON again',
        );
    }

    public function test_a_legacy_row_can_still_be_cleared_to_null(): void
    {
        $mandant = $this->superAdminMandant();
        $this->writeLegacyCleartextSmtpConfig($mandant, ['host' => 'legacy.example.com']);

        // "Cleared" is the explicit all-null config (not a SQL NULL) — that is
        // what makes `smtp_has_password` flip to false. The pre-existing
        // contract, unchanged here. The API view is the same config without the
        // `password` key.
        $cleared = [
            'host' => null,
            'port' => null,
            'username' => null,
            'password' => null,
            'encryption' => null,
        ];

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$mandant->id, ['smtp_config' => null])
            ->assertOk()
            ->assertJsonPath('data.smtp_config', array_diff_key($cleared, ['password' => null]))
            ->assertJsonPath('data.smtp_has_password', false);

        $this->assertSame($cleared, $mandant->fresh()->smtp_config);
    }

    public function test_a_legacy_row_without_a_new_password_loses_the_unreadable_one(): void
    {
        // Documented consequence, pinned: there is nothing to merge into, so the
        // payload becomes the whole config. The operator cannot keep a password
        // they cannot read — the API never hands it out (`smtp_has_password`
        // only reports presence), so the operator must supply it again.
        $mandant = $this->superAdminMandant();
        $this->writeLegacyCleartextSmtpConfig($mandant, [
            'host' => 'legacy.example.com',
            'password' => 'alt',
        ]);

        $this->actingAsApi($this->superAdmin())
            ->putJson('/api/admin/mandants/'.$mandant->id, [
                'smtp_config' => ['host' => 'neu.example.com'],
            ])
            ->assertOk()
            ->assertJsonPath('data.smtp_config.host', 'neu.example.com')
            ->assertJsonPath('data.smtp_has_password', false);
    }

    /* ---------------------------------------------------------------------
     | F4 — one legacy row must not 500 the whole list
     | ------------------------------------------------------------------- */

    public function test_the_mandant_index_survives_a_legacy_cleartext_row(): void
    {
        $broken = Mandant::factory()->create(['name' => 'Legacy Verband']);
        $healthy = Mandant::factory()->create([
            'name' => 'Gesunder Verband',
            'smtp_config' => ['host' => 'mail.example.com', 'port' => 587, 'password' => 'geheim'],
        ]);
        $this->writeLegacyCleartextSmtpConfig($broken, ['host' => 'legacy.example.com']);

        $response = $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/mandants')
            ->assertOk();

        $byId = collect($response->json('data'))->keyBy('id');

        // The legacy row degrades to "no config" instead of taking the whole
        // list down with it.
        $this->assertNull($byId[$broken->id]['smtp_config']);
        $this->assertFalse($byId[$broken->id]['smtp_has_password']);

        // A healthy row in the SAME response keeps its config — the catch must
        // not swallow anything but the failing row.
        $this->assertSame('mail.example.com', $byId[$healthy->id]['smtp_config']['host']);
        $this->assertTrue($byId[$healthy->id]['smtp_has_password']);
        $this->assertArrayNotHasKey('password', $byId[$healthy->id]['smtp_config']);
    }

    public function test_a_legacy_cleartext_row_does_not_block_mail_delivery(): void
    {
        // `MandantMailerService::send()` swallows Throwable, so a DecryptException
        // out of `transportFor()` did not surface as a 500 — it silently DROPPED
        // the mail, not even falling back to the default mailer. An unreadable
        // config must degrade to "no mandant relay", which is the documented
        // fallback path.
        $mandant = Mandant::factory()->create();
        $this->writeLegacyCleartextSmtpConfig($mandant, ['host' => 'legacy.example.com']);

        $this->assertNull(
            app(MandantMailerService::class)->transportFor(Mandant::query()->findOrFail($mandant->id)),
            'an unreadable smtp_config means "no relay", not "throw"',
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers for the legacy-row fixtures
     | ------------------------------------------------------------------- */

    /**
     * Overwrite `smtp_config` with plain JSON, i.e. exactly what a row written
     * before the `encrypted:json` cast holds. Bypasses the cast on purpose.
     *
     * @param  array<string, mixed>  $config
     */
    private function writeLegacyCleartextSmtpConfig(Mandant $mandant, array $config): void
    {
        DB::table('mandants')->where('id', $mandant->id)->update([
            'smtp_config' => json_encode($config),
        ]);

        $mandant->refresh();
    }

    private function superAdminMandant(): Mandant
    {
        return Mandant::factory()->create();
    }

    private function superAdmin(): User
    {
        $this->seed(RoleSeeder::class);

        $user = User::factory()->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->valueOrFail('id'),
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $user;
    }

    /* ---------------------------------------------------------------------
     | WP-6-e — maintenance mode driver
     | ------------------------------------------------------------------- */

    /**
     * The `MaintenanceModeManager` of Laravel 13.32.0 — every driver it can
     * actually build, mapped to the FQCN it must return. Read from the vendor
     * source instead of hardcoded so a framework bump that adds or drops a
     * driver fails here rather than at runtime.
     *
     * @return array<string, class-string>
     */
    private function implementedMaintenanceDrivers(): array
    {
        $source = File::get(
            base_path('vendor/laravel/framework/src/Illuminate/Foundation/MaintenanceModeManager.php'),
        );

        $this->assertStringContainsString(
            'namespace Illuminate\Foundation;',
            $source,
            'the driver classes are resolved in the manager\'s own namespace',
        );

        preg_match_all(
            '/protected function create(\w+)Driver\(\):\s*(\w+)/',
            $source,
            $matches,
            PREG_SET_ORDER,
        );

        $drivers = [];

        foreach ($matches as $match) {
            $drivers[strtolower($match[1])] = 'Illuminate\\Foundation\\'.$match[2];
        }

        $this->assertNotEmpty($drivers, 'could not read the implemented drivers out of MaintenanceModeManager');

        return $drivers;
    }

    /**
     * F1 (CRITICAL): the SHIPPED default must be a driver the manager can build.
     *
     * The regression this pins: `config/app.php` defaulted to `database`, which
     * does not exist. `PreventRequestsDuringMaintenance` sits in the global
     * middleware stack and calls `maintenanceMode()->active()` on EVERY request,
     * so an unbuildable driver is not a degraded feature — it is a 500 on every
     * request. The suite stayed green because `phpunit.xml` pins `file`, so the
     * tests never went through the container with the shipped value.
     *
     * Resolved through the CONTAINER on purpose, exactly like the global
     * middleware does, instead of being asserted on the config source.
     */
    public function test_the_shipped_maintenance_driver_default_resolves_through_the_container(): void
    {
        $default = $this->shippedMaintenanceDriver();

        config(['app.maintenance.driver' => $default]);

        $manager = $this->app->make(MaintenanceModeManager::class);

        $this->assertInstanceOf(MaintenanceModeManager::class, $manager);
        $this->assertSame($default, $manager->getDefaultDriver());

        // `Application::maintenanceMode()` resolves the contract, which is the
        // driver itself — this is the exact call the global middleware makes and
        // the exact call that threw `Driver [database] not supported.`
        $driver = $this->app->maintenanceMode();

        $this->assertInstanceOf($this->implementedMaintenanceDrivers()[$default], $driver);
        $this->assertFalse($driver->active());
    }

    public function test_no_request_fails_because_of_the_shipped_maintenance_driver(): void
    {
        // The end-to-end form of the same defect: with `database` as the driver
        // the global middleware turns EVERY request into a 500 carrying
        // "Driver [database] not supported.". `/api/portal/overview` is public,
        // unauthenticated and cheap, and it answers 404 in the suite (no mandant
        // context) — but it must be an APPLICATION answer, never a framework error.
        config(['app.maintenance.driver' => $this->shippedMaintenanceDriver()]);

        $response = $this->getJson('/api/portal/overview')->assertNotFound();

        $this->assertStringNotContainsString('not supported', $response->getContent());
        $this->assertStringNotContainsString('InvalidArgumentException', $response->getContent());
    }

    public function test_the_shipped_maintenance_driver_is_file(): void
    {
        $this->assertSame(
            'file',
            $this->shippedMaintenanceDriver(),
            'the default must be a driver Laravel 13 implements; `file` is the framework default and the only one that needs no shared backend',
        );
    }

    public function test_the_configured_driver_is_honoured_at_runtime(): void
    {
        config(['app.maintenance.driver' => 'file']);

        $this->assertSame('file', config('app.maintenance.driver'));
        $this->assertSame('database', config('app.maintenance.store'));
    }

    /**
     * The driver the manager can build for each name, so a future change to the
     * configured driver cannot reintroduce an unimplemented one.
     */
    public function test_the_multi_instance_driver_is_the_cache_driver(): void
    {
        $drivers = $this->implementedMaintenanceDrivers();

        $this->assertArrayHasKey(
            'cache',
            $drivers,
            'the documented multi-instance driver must exist — `cache` is what propagates across app containers',
        );
        $this->assertArrayNotHasKey(
            'database',
            $drivers,
            'there is no `database` maintenance driver in Laravel 13; documenting it is the F1 defect',
        );

        config(['app.maintenance.driver' => 'cache']);

        $driver = $this->app->maintenanceMode();

        $this->assertInstanceOf(CacheBasedMaintenanceMode::class, $driver);
        $this->assertFalse($driver->active());
    }

    public function test_the_file_and_array_drivers_resolve_too(): void
    {
        $expected = [
            'file' => FileBasedMaintenanceMode::class,
            'array' => ArrayMaintenanceMode::class,
        ];

        foreach ($expected as $driver => $class) {
            config(['app.maintenance.driver' => $driver]);

            $this->assertInstanceOf($class, $this->app->maintenanceMode(), "driver: {$driver}");
        }
    }

    public function test_the_env_example_ships_a_driver_laravel_implements(): void
    {
        $example = File::get(base_path('.env.example'));

        $this->assertMatchesRegularExpression(
            '/^APP_MAINTENANCE_DRIVER=(file|cache|array)$/m',
            $example,
            'copying .env.example must not set a driver that Laravel 13 cannot build — an unbuildable driver 500s every request',
        );

        preg_match('/^APP_MAINTENANCE_DRIVER=(\S+)$/m', $example, $match);

        $this->assertArrayHasKey(
            $match[1],
            $this->implementedMaintenanceDrivers(),
            'the driver shipped in .env.example must exist in MaintenanceModeManager',
        );
    }

    public function test_the_suite_pin_is_an_explicit_isolation_choice(): void
    {
        // Documented, not accidental: the test suite keeps the `file` driver so
        // a maintenance-mode test can never write into the test database. If
        // this pin is ever removed the container test above becomes the only
        // guard.
        $phpunit = File::get(base_path('phpunit.xml'));

        $this->assertStringContainsString(
            '<env name="APP_MAINTENANCE_DRIVER" value="file"/>',
            $phpunit,
        );
    }

    /**
     * The maintenance driver `config/app.php` SHIPS — read from the file's own
     * `env('APP_MAINTENANCE_DRIVER', …)` default, not from the resolved config.
     *
     * `phpunit.xml` pins `APP_MAINTENANCE_DRIVER=file`, so `config(...)` inside
     * the suite can only ever report the pin. The default is read by evaluating
     * the config file with that one variable removed from every environment
     * adapter (`$_SERVER`, `$_ENV`, `putenv`), which is exactly what the
     * `env()` helper consults. The environment is restored in a `finally`, so
     * the rest of the suite sees the pinned value again.
     */
    private function shippedMaintenanceDriver(): string
    {
        $name = 'APP_MAINTENANCE_DRIVER';

        $restore = [
            'server' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
            'env' => array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
            'putenv' => getenv($name),
        ];

        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        try {
            $config = require base_path('config/app.php');
        } finally {
            if ($restore['server'] !== null) {
                $_SERVER[$name] = $restore['server'];
            }

            if ($restore['env'] !== null) {
                $_ENV[$name] = $restore['env'];
            }

            if ($restore['putenv'] !== false) {
                putenv($name.'='.$restore['putenv']);
            }
        }

        $this->assertIsArray($config, 'config/app.php must return an array');
        $this->assertArrayHasKey('maintenance', $config);

        return (string) $config['maintenance']['driver'];
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
