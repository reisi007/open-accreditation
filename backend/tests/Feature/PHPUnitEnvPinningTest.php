<?php

namespace Tests\Feature;

use App\Support\TrustedProxyConfig;
use Dotenv\Repository\RepositoryInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use SimpleXMLElement;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * THE REGRESSION GUARD for the hermeticity of `phpunit.xml` — position 42.
 *
 * ## What this is about
 *
 * `php artisan test` reads `backend/.env`, the file the developer's dev stack
 * also needs. `phpunit.xml` pins a subset of the `env()` keys; every key it
 * does not pin can be decided by that file. This suite asserts that the ten
 * keys measured to decide test outcomes are pinned, that the pinned values are
 * the ones the product code documents, and — the part a config assertion
 * cannot do on its own — that a hostile value in a `.env` file really loses
 * against the pin.
 *
 * ## The measured exposure (full suite, only `backend/.env` varied)
 *
 * Reference: 1732 passed / 0 failed / 1 skipped, on this host, with
 * `APP_URL=https://accreditation.test` exported.
 *
 * | Key                       | hostile `.env` value              | failed |
 * |---------------------------+-----------------------------------+--------|
 * | TRUSTED_HOSTS             | `^(.+\.)?verband-live\.example\.com$` | 5 |
 * | APP_PREVIOUS_KEYS         | raw base64 key, 32 bytes          | 35 |
 * | REQUIRE_ORIGIN_HEADER     | `true`                            | 3 |
 * | TRUSTED_PROXIES           | `10.0.0.0/8,192.168.0.0/16`       | 3 |
 * | JWT_CROSS_SITE_COOKIE     | `true`                            | 5 |
 * | SESSION_SECURE_COOKIE     | `false`                           | 1 |
 * | SESSION_ENCRYPT           | `true`                            | 5 |
 * | MEDIA_ROOT                | `/srv/media/accreditation`        | 1 |
 * | MANDANTS_FALLBACK_MANDANT | `hauptverband`                    | 0 |
 * | MANDANTS_FALLBACK_MANDANT | `verband-a` (a slug the factories create) | 2 |
 * | WALLET_PASS_TYPE_ID       | `pass.com.accreditation.verband`  | 1 |
 *
 * Two of those numbers carry their own caveat and both are stated where they
 * are used, because a table that flattens them would be the same error the
 * guard exists to prevent:
 *
 * - `APP_PREVIOUS_KEYS`: 35 is what an **invalid** value costs. The 35 are
 *   `RuntimeException: Unsupported cipher or incorrect key length`, not "one
 *   more key breaks verification". `EncryptionServiceProvider::parseKey()`
 *   strips a `base64:` prefix, so a 32-byte key **with** the prefix measured 0
 *   failed (in 4 of 5 full runs; one run reported the same 35 with the same
 *   value and could not be reproduced in four more — recorded as an open
 *   observation in the `phpunit.xml` comment rather than smoothed over).
 *   `base64_encode()` output as a *plain* value is 44 characters and is
 *   rejected, which is why 35 is the number for the un-prefixed variant too.
 * - `MANDANTS_FALLBACK_MANDANT`: the exposure is bound to the slug. It only
 *   resolves when the value names a mandant a test creates; see
 *   `test_a_hostile_env_file_cannot_reach_the_fallback_mandant()`.
 *
 * ## Why the file is parsed, not `config()`
 *
 * `config('…')` inside this suite can only ever report the pin, so it cannot
 * tell "the pin is in force" from "the pin is gone and the checkout's `.env`
 * happens to hold the same value". Reading the committed file answers the
 * question that matters. Same reasoning and same idiom as
 * `AppUrlHermeticityTest` and `SchemaHardeningTest`.
 */
class PHPUnitEnvPinningTest extends TestCase
{
    /**
     * The keys this file holds in place, with the value each one is pinned to.
     *
     * Every value is the value the product documentation or the shipped
     * default already names — the point of the block is not to invent a
     * testing-only configuration, but to make the shipped one non-negotiable.
     *
     * @var array<string, string>
     */
    private const PINS = [
        'TRUSTED_HOSTS' => '',
        'TRUSTED_PROXIES' => '',
        'REQUIRE_ORIGIN_HEADER' => 'false',
        'APP_PREVIOUS_KEYS' => '',
        'JWT_CROSS_SITE_COOKIE' => 'false',
        'SESSION_SECURE_COOKIE' => 'true',
        'SESSION_ENCRYPT' => 'false',
        'MEDIA_ROOT' => '',
        'MANDANTS_FALLBACK_MANDANT' => '',
        'WALLET_PASS_TYPE_ID' => 'pass.accriditation.test',
    ];

    /**
     * Keys deliberately NOT covered by the hostile-`.env` test.
     *
     * These two are the ones `.env.example` ships **uncommented**, so a
     * checkout made from it already carries them in `$_ENV`/`$_SERVER` before
     * any pin is considered, and a throwaway boot could not tell "the pin won"
     * from "the ambient `.env` already held that value". Claiming the property
     * for them would be a measurement that proves nothing, so they are listed
     * here instead — and
     * `test_the_keys_the_hostile_env_test_cannot_cover_are_why_it_cannot()`
     * checks that claim against `.env.example` rather than taking it on trust.
     *
     * They are still pinned, and `test_the_pinned_value_is_the_one_the_config_
     * resolves()` covers their resolved side.
     *
     * @var array<int, string>
     */
    private const NOT_HOSTILE_ENV_TESTABLE = ['MEDIA_ROOT', 'SESSION_ENCRYPT'];

    private ?string $envDirectory = null;

    private ?RepositoryInterface $originalRepository = null;

    private bool $repositoryDropped = false;

    /* ---------------------------------------------------------------------
     | 1 — the pins exist, with the documented value
     | ------------------------------------------------------------------- */

    /**
     * Deleting a pin makes this red — that is the whole regression.
     *
     * The XML is parsed rather than string-matched so that a pin with the
     * wrong value, a duplicated pin, or a pin that moved out of `<php>` is
     * caught as well; `simplexml_load_file()` is also what makes the count
     * assertion below meaningful instead of decorative.
     */
    public function test_every_measured_key_is_pinned_in_the_suite_config(): void
    {
        $pins = $this->suitePins();

        foreach (self::PINS as $key => $value) {
            $this->assertArrayHasKey(
                $key,
                $pins,
                "phpunit.xml does not pin {$key}: a value in backend/.env decides this key's "
                .'behaviour during the run. See the measured table in this file\'s docblock.',
            );

            $this->assertSame(
                $value,
                $pins[$key],
                "phpunit.xml pins {$key} to a value the product documentation does not name.",
            );
        }
    }

    /**
     * The pins are declarations, not leftovers of the APP_URL experiment.
     *
     * `force="true"` means something very specific here: PHPUnit's
     * `<env>` handler writes to `putenv` and `$_ENV`, never to `$_SERVER`, and
     * Laravel's `Env::getRepository()` consults the `ServerConstAdapter`
     * **first**. A forced pin therefore also wins against a shell export
     * (`KEY=… php artisan test`), a non-forced one only against the `.env`
     * file. `APP_KEY`, `JWT_SECRET` and `APP_URL` are forced because both
     * directions were measured there; the ten keys here were only ever
     * measured against the `.env`, so forcing them would assert a property
     * nobody measured.
     *
     * The assertion is that these ten are NOT forced. If somebody later
     * measures the shell direction and turns one on, this test fails and the
     * comment above has to be rewritten with the measurement — that is the
     * intended tripwire, not an accident.
     */
    public function test_the_measured_pins_are_not_forced(): void
    {
        $forced = $this->forcedPins();

        foreach (array_keys(self::PINS) as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $forced,
                "phpunit.xml forces {$key}. Force is only meaningful against a process value, and "
                .'that direction was never measured for this key — see this method\'s docblock.',
            );
        }
    }

    /* ---------------------------------------------------------------------
     | 2 — the pinned value is the one the config resolves
     | ------------------------------------------------------------------- */

    /**
     * The product side of the contract: each pin has to match what
     * `config/*.php` does with the key, not just what the file says.
     *
     * This is what makes the pins *meaningful* rather than present. A pin that
     * disagrees with the resolver — `MEDIA_ROOT=''` where the resolver would
     * collapse the disk root, `TRUSTED_PROXIES` at anything but the loopback
     * default — is a pin the suite would silently measure around.
     *
     * `TRUSTED_PROXIES` and `APP_PREVIOUS_KEYS` are asserted through the
     * resolvers rather than through `config()` alone, because their whole
     * contract is the fallback: an empty value is not "empty", it is "use the
     * shipped default".
     *
     * The four boolean pins are asserted on **type**, not truthiness, and that
     * is deliberate. PHPUnit's XML loader types `value="false"` as the bool
     * `false` (measured), `PhpHandler` then writes `putenv('KEY=')`, and
     * `env()` resolves the empty string — so without `verbatim="true"` the pin
     * still behaves correctly and `assertFalse()` on a truthiness cast would
     * never notice. `assertSame(false, …)` does. See
     * `test_a_boolean_pin_without_verbatim_would_resolve_to_a_string()`.
     */
    public function test_the_pinned_value_is_the_one_the_config_resolves(): void
    {
        // Host allow-list: blank keeps the dev wildcards, which is what the
        // `*.test` hostnames every mandant test creates are matched by.
        $this->assertSame('', config('security.trusted_hosts'));
        $this->assertSame(
            ['127.0.0.1', '::1'],
            TrustedProxyConfig::ips(),
            'a blank TRUSTED_PROXIES must resolve to the loopback default, not to an empty trust list.',
        );
        $this->assertSame(false, config('security.require_origin_header'));

        // Key rotation: blank means "no previous keys", which is what
        // `QrTokenV2Test` and `AllocationQrTokenUpgradeTest` set up around.
        $this->assertSame([], config('app.previous_keys'));

        $this->assertSame(false, config('jwt.cross_site_cookie'));
        $this->assertSame(true, config('session.secure'));
        $this->assertSame(false, config('session.encrypt'));
        $this->assertSame('', config('mandants.fallback_mandant'));
        $this->assertSame('pass.accriditation.test', config('wallet.apple.pass_type_id'));

        // The media disk root, resolved: `filesystems.php` is
        // `env('MEDIA_ROOT') ?: storage_path('app/media')`, and that `?:` is the
        // only thing standing between a blank value and a write relative to the
        // process CWD.
        $this->assertSame(storage_path('app/media'), config('filesystems.disks.media.root'));
    }

    /**
     * `verbatim="true"` is load-bearing on the four boolean pins, and this is
     * what it buys — measured, not assumed.
     *
     * PHPUnit's XML loader types `value="false"` as the **bool** `false`
     * (`Xml\Loader::valueFromString()`), and `PhpHandler::handleEnvVariables()`
     * then writes `(string) false`, i.e. `putenv('KEY=')`. `env()` resolves an
     * empty string to `''`. Behaviourally that is still falsy, so every
     * truthiness assertion in the suite would stay green — which is exactly
     * why the pin is written with `verbatim="true"` and asserted with
     * `assertSame()` rather than `assertFalse()`.
     *
     * This test proves the difference by *evaluating both forms* through the
     * same repository the config resolver uses, so it keeps its value even if
     * PHPUnit's XML typing ever changes: it asserts what the resolver returns
     * for each form, not what PHPUnit's source code currently says.
     */
    public function test_a_boolean_pin_without_verbatim_would_resolve_to_a_string(): void
    {
        // Control: the form used in phpunit.xml.
        $this->assertSame(false, $this->resolveEnv('false'));

        // The form a pin without `verbatim="true"` produces: an empty string.
        $this->assertSame('', $this->resolveEnv(''));

        $this->assertNotSame(
            $this->resolveEnv(''),
            $this->resolveEnv('false'),
            'the two forms stopped differing. If PHPUnit no longer types value="false", this test '
            .'loses its reason to exist — delete it rather than let it assert nothing.',
        );
    }

    /* ---------------------------------------------------------------------
     | 3 — a hostile `.env` really loses (and the test can tell)
     | ------------------------------------------------------------------- */

    /**
     * The property a config assertion cannot show: a `.env` carrying a hostile
     * value for a pinned key does not change what the suite resolves.
     *
     * It is proved the way an operator's value travels: a real dotenv file in
     * a real directory, loaded by `LoadEnvironmentVariables` through Laravel's
     * **immutable** `Env` repository, into a throwaway application. Immutable
     * is the whole mechanism — dotenv refuses to write a name the repository
     * already holds, and PHPUnit's `<env>` pin put it there before boot. If
     * the pin were deleted, the file would win.
     *
     * ## The control is what makes this a measurement
     *
     * `MANDANTS_CACHE_TTL` is deliberately **not** pinned, so it is the
     * negative case in the same boot: the same throwaway file must change it.
     * Without that control the first half could pass for the wrong reason — a
     * dotenv file that silently failed to load, a wrong environment path, an
     * `Env::$repository` that was never really dropped — and every assertion
     * above it would still be green. The control is what turns "the pin wins"
     * from a claim into an observation.
     *
     * The keys the hostile file sets are `self::PINS` minus
     * `self::NOT_HOSTILE_ENV_TESTABLE`, minus the control — derived, not
     * hand-listed, so a pin added later is covered without editing this test.
     */
    public function test_a_hostile_env_file_cannot_reach_the_pinned_config(): void
    {
        $this->assertNotContains(
            'MANDANTS_CACHE_TTL',
            $this->suitePins(),
            'the control key of this test must stay unpinned, otherwise the control stops controlling.',
        );

        // Precondition: the hostile file really carries a value for every key
        // this test claims to cover. Without it a typo in a key name would
        // quietly shrink the coverage and the test would still be green.
        $hostile = [
            'MANDANTS_CACHE_TTL' => '17',
            'TRUSTED_HOSTS' => '^(.+\.)?live\.example\.com$',
            'TRUSTED_PROXIES' => '10.0.0.0/8',
            'REQUIRE_ORIGIN_HEADER' => 'true',
            'APP_PREVIOUS_KEYS' => 'Wg5YKb7nuBDIXW+aTEbaorxwUIs1dsBZMp/lAqcoRP4=',
            'JWT_CROSS_SITE_COOKIE' => 'true',
            'SESSION_SECURE_COOKIE' => 'false',
            'MANDANTS_FALLBACK_MANDANT' => 'live-mandant',
            'WALLET_PASS_TYPE_ID' => 'pass.com.live.example',
        ];

        $expected = array_values(array_diff(
            array_keys(self::PINS),
            self::NOT_HOSTILE_ENV_TESTABLE,
        ));

        $this->assertSame(
            $expected,
            array_values(array_diff(array_keys($hostile), ['MANDANTS_CACHE_TTL'])),
            'the hostile .env file must carry a value for exactly the pinned keys this test covers. '
            .'See self::NOT_HOSTILE_ENV_TESTABLE for the two it deliberately cannot.',
        );

        $config = $this->bootWithHostileEnvFile(
            array_map(
                static fn (string $key, string $value): string => $key.'='.$value,
                array_keys($hostile),
                array_values($hostile),
            ),
        )->make('config');

        // Control first, and asserted first: if the throwaway file did not
        // reach the resolver, nothing below this line means anything.
        $this->assertSame(17, $config->get('mandants.cache_ttl'), 'precondition: the throwaway .env reached the resolver');

        // Asserted against the PINNED value, never against the hostile one: the
        // point is that the file lost, so writing the hostile literal here would
        // assert the opposite of the property.
        foreach ([
            'TRUSTED_HOSTS' => ['security.trusted_hosts', self::PINS['TRUSTED_HOSTS']],
            'TRUSTED_PROXIES' => ['security.trusted_proxies', self::PINS['TRUSTED_PROXIES']],
            'REQUIRE_ORIGIN_HEADER' => ['security.require_origin_header', self::PINS['REQUIRE_ORIGIN_HEADER'] === 'true'],
            'APP_PREVIOUS_KEYS' => ['app.previous_keys', []],
            'JWT_CROSS_SITE_COOKIE' => ['jwt.cross_site_cookie', self::PINS['JWT_CROSS_SITE_COOKIE'] === 'true'],
            'SESSION_SECURE_COOKIE' => ['session.secure', self::PINS['SESSION_SECURE_COOKIE'] === 'true'],
            'MANDANTS_FALLBACK_MANDANT' => ['mandants.fallback_mandant', self::PINS['MANDANTS_FALLBACK_MANDANT']],
            'WALLET_PASS_TYPE_ID' => ['wallet.apple.pass_type_id', self::PINS['WALLET_PASS_TYPE_ID']],
        ] as $key => [$configKey, $expected]) {
            $this->assertSame(
                $expected,
                $config->get($configKey),
                "the throwaway .env changed {$configKey}: the hostile file set {$key}='"
                .$hostile[$key]."' and it came through. A pin only holds while the variable is present in "
                .'the process environment when the application boots.',
            );
        }

        // The trust list is the resolvable form, same as in the suite's own
        // process: a blank value must not become "trust nothing".
        $this->assertSame(['127.0.0.1', '::1'], TrustedProxyConfig::ips());
    }

    /**
     * `MANDANTS_FALLBACK_MANDANT` has the one exposure the table flattens: a
     * slug nobody uses costs 0 failures.
     *
     * `MandantContext::default()` only consults the config slug when no
     * `is_primary` row exists, and the factories set `is_primary => false`.
     * So the key bites exactly when the value names a mandant that some test
     * happens to have created — measured: `hauptverband` → 0 failed,
     * `verband-a` → 2 failed. A pin that is "empty" removes the dependence on
     * that coincidence, which is the whole reason this key is in the block
     * even though a "plausible" value showed nothing.
     */
    public function test_a_hostile_env_file_cannot_reach_the_fallback_mandant(): void
    {
        $config = $this->bootWithHostileEnvFile([
            'MANDANTS_FALLBACK_MANDANT=verband-a',
        ])->make('config');

        $this->assertSame(
            '',
            $config->get('mandants.fallback_mandant'),
            'the fallback slug followed the .env. MandantContext::default() would then resolve to '
            .'whatever mandant a test created with that slug — measured 2 failures with "verband-a".',
        );
    }

    /**
     * The exclusion list is a claim about `.env.example`, so it is checked
     * against the file.
     *
     * Two keys are skipped by the hostile-`.env` test, and the reason is that
     * `.env.example` ships them **uncommented** — a checkout made from it
     * carries them in `$_ENV`/`$_SERVER` before the throwaway application
     * boots, so a hostile `.env` cannot be shown to lose against them.
     * Reading the reason out of the constant's docblock and asserting it
     * against the file is what keeps the exclusion from quietly becoming a
     * gap: uncomment the key in `.env.example` and this test goes red, telling
     * the next person the key has become testable.
     */
    public function test_the_keys_the_hostile_env_test_cannot_cover_are_why_it_cannot(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertIsString($example, '.env.example is not readable.');

        $uncommented = [];

        foreach (preg_split('/\R/', $example) ?: [] as $line) {
            if (preg_match('/^([A-Z0-9_]+)=/', $line, $matches) === 1) {
                $uncommented[] = $matches[1];
            }
        }

        $excluded = array_values(array_filter(
            self::NOT_HOSTILE_ENV_TESTABLE,
            static fn (string $key): bool => in_array($key, $uncommented, true),
        ));

        $this->assertSame(
            self::NOT_HOSTILE_ENV_TESTABLE,
            $excluded,
            'NOT_HOSTILE_ENV_TESTABLE must list exactly the pinned keys .env.example ships UNCOMMENTED. '
            .'A key that is commented out there IS testable against a hostile .env and must be added to '
            .'test_a_hostile_env_file_cannot_reach_the_pinned_config().',
        );
    }

    /* ---------------------------------------------------------------------
     | 4 — MAIL_MAILER=array, the structural half of the same decision
     | ------------------------------------------------------------------- */

    /**
     * `MAIL_MAILER=array` is not a preference; it is what makes a forgotten
     * `Mail::fake()` harmless.
     *
     * `AuthController` sends the activation mail with `Mail::to(...)->send()`,
     * i.e. through the **default** mailer. With `array` that resolves to an
     * `ArrayTransport`, which writes into memory and has no socket to open —
     * so the two tests that used to answer 500 when no mail catcher was
     * running can no longer depend on one.
     *
     * The transport class is asserted, not the mailer name, because the name
     * would stay green under a `<env>` pin that the resolver ignored.
     *
     * ## The residual, measured
     *
     * `MandantMailerService::deliver()` asks for `Mail::mailer('smtp')` **by
     * name**, so that path bypasses the default mailer entirely and still goes
     * through the SMTP transport on `MAIL_HOST`/`MAIL_PORT`. `send()` no longer
     * dials — since Position 45 (2026-10-02) it only dispatches
     * `SendMandantMail`, and the transport is spoken to in `deliver()`.
     *
     * Instrumented over the full suite on 2026-10-02, before the queue change:
     * **132** sends took that path and every one threw
     * `TransportException: Connection could not be established with host
     * "127.0.0.1:1025"`. They were swallowed by `send()`'s `catch (Throwable)`,
     * which is why the suite was green. That swallow is GONE: `deliver()`
     * propagates, the queue retries, and after the cap the job is dead-lettered
     * in `failed_jobs` (see `App\Jobs\SendMandantMail`). The test classes that
     * only exercise the allocation logic now fake the mail explicitly.
     *
     * So `array` closes the path that could fail **visibly** (the two 500s
     * above) and not every path. This test must not be read as "the suite can
     * no longer touch a socket anywhere" — the `smtp` mailer still resolves to a
     * real transport, and it is named on purpose so a Verband's own relay is
     * used in production.
     */
    public function test_the_suite_default_mailer_cannot_open_a_socket(): void
    {
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('array', $this->suitePins()['MAIL_MAILER'] ?? null);

        $this->assertInstanceOf(
            ArrayTransport::class,
            Mail::mailer()->getSymfonyTransport(),
            'the default mailer of the suite is not in-memory. A test that forgets Mail::fake() would '
            .'re-open the dependency on a running mail catcher that MAIL_MAILER=array exists to close.',
        );

        // The other half of the statement, and the half that could be false: the
        // `smtp` mailer still resolves to a REAL transport, because
        // `MandantMailerService::send()` names it. Asserting it keeps the
        // residual honest — if a later change makes the service use the default
        // mailer, this turns red and the docblock above can be shortened.
        $this->assertInstanceOf(
            EsmtpTransport::class,
            Mail::mailer('smtp')->getSymfonyTransport(),
            'MandantMailerService no longer reaches a real SMTP transport. If that was deliberate, update '
            .'this method\'s docblock: the "132 swallowed sends" residual it describes is gone, and so is '
            .'the reason MAIL_HOST/MAIL_PORT are still pinned here.',
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * Every `<env>` pin in `phpunit.xml`, as `name => value`.
     *
     * Parsed, not string-matched, so that a pin with the wrong value or a
     * `<env>` that escaped the `<php>` block cannot pass unnoticed.
     *
     * @return array<string, string>
     */
    private function suitePins(): array
    {
        $xml = simplexml_load_file(base_path('phpunit.xml'));

        $this->assertInstanceOf(SimpleXMLElement::class, $xml, 'phpunit.xml is not readable XML.');

        $pins = [];

        foreach ($xml->php->env ?? [] as $env) {
            $name = (string) $env['name'];

            $this->assertArrayNotHasKey(
                $name,
                $pins,
                "phpunit.xml pins {$name} twice. Two values for one key is a coin toss, not a decision.",
            );

            $pins[$name] = (string) $env['value'];
        }

        $this->assertNotEmpty($pins, 'phpunit.xml declares no <env> pins at all.');

        return $pins;
    }

    /**
     * The subset of `suitePins()` that carries `force="true"`.
     *
     * @return array<string, string>
     */
    private function forcedPins(): array
    {
        $xml = simplexml_load_file(base_path('phpunit.xml'));

        $this->assertInstanceOf(SimpleXMLElement::class, $xml, 'phpunit.xml is not readable XML.');

        $forced = [];

        foreach ($xml->php->env ?? [] as $env) {
            if (isset($env['force']) && (string) $env['force'] === 'true') {
                $forced[(string) $env['name']] = (string) $env['value'];
            }
        }

        return $forced;
    }

    /**
     * `env('PROBE')` with a value put into the process environment, the way
     * PHPUnit's `<env>` handler puts a pin there.
     *
     * `putenv` alone is enough and is the most faithful choice: Laravel's
     * `Env::getOption()` reads the repository the framework itself reads, and
     * `putenv` is the one adapter that is guaranteed to be a fresh write
     * (the `$_ENV` / `$_SERVER` entries for a probe key do not exist, so
     * nothing is being overwritten and nothing cached is being read).
     */
    private function resolveEnv(string $value): mixed
    {
        $key = 'HERMETICITY_PROBE';

        putenv($key.'='.$value);

        try {
            return env($key);
        } finally {
            putenv($key);
        }
    }

    /**
     * Boot a throwaway application whose own `.env` holds the given lines.
     *
     * The shape is `TrustedProxyEnvFileTest`'s, and the difference that matters
     * is the one thing that test does on purpose and this one must NOT do:
     * **the pinned variables stay in the process environment.** That test
     * quarantines them, because it is about proving that an operator's `.env`
     * reaches the framework. Here the pinned value has to be present so that
     * the immutable repository refuses to overwrite it — that refusal is the
     * property under test.
     *
     * The cached repository is still dropped, because it holds every value read
     * during this process's own boot; without that, `Repository::has()` would
     * answer from the cache and the hostile file would be ignored whether or
     * not the pin existed.
     *
     * @param  array<int, string>  $lines
     */
    private function bootWithHostileEnvFile(array $lines): Application
    {
        $this->originalRepository = $this->envRepository();
        $this->setEnvRepository(null);
        $this->repositoryDropped = true;

        $this->envDirectory = sys_get_temp_dir().'/accr-hermeticity-'.bin2hex(random_bytes(8));
        mkdir($this->envDirectory);
        file_put_contents($this->envDirectory.'/.env', implode(PHP_EOL, $lines).PHP_EOL);

        $app = require base_path('bootstrap/app.php');
        $app->useEnvironmentPath($this->envDirectory);

        // The **console** kernel, for the same reason `TrustedProxyEnvFileTest`
        // uses it: its bootstrapper list is the one production also runs
        // (`LoadEnvironmentVariables` → `LoadConfiguration` → … →
        // `BootProviders`), and the JWT provider needs a bound `request`,
        // which only the console kernel's `SetRequestForConsole` provides.
        $app->make(Kernel::class)->bootstrap();

        // The fresh instance becomes the test's instance, so `tearDown()` tears
        // down the container these assertions actually ran against.
        $this->app = $app;

        return $app;
    }

    protected function tearDown(): void
    {
        if ($this->repositoryDropped) {
            $this->setEnvRepository($this->originalRepository);
            $this->originalRepository = null;
            $this->repositoryDropped = false;
        }

        if ($this->envDirectory !== null && is_dir($this->envDirectory)) {
            // `*` does not match dotfiles, and the dotenv file is the only one.
            foreach ((array) glob($this->envDirectory.'/{,.}*', GLOB_BRACE) as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($this->envDirectory);
        }

        $this->envDirectory = null;

        parent::tearDown();
    }

    private function envRepository(): ?RepositoryInterface
    {
        $value = $this->envRepositoryProperty()->getValue();

        return $value instanceof RepositoryInterface ? $value : null;
    }

    private function setEnvRepository(?RepositoryInterface $repository): void
    {
        $this->envRepositoryProperty()->setValue($repository);
    }

    private function envRepositoryProperty(): ReflectionProperty
    {
        $property = new ReflectionProperty(Env::class, 'repository');
        $property->setAccessible(true);

        return $property;
    }
}
