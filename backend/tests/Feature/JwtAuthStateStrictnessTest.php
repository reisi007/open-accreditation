<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use ReflectionClass;
use Tests\TestCase;

/**
 * STRICT MODE: the measurement behind the whole cookie-channel migration,
 * and the guard on the measurement itself.
 *
 * ## What is being protected
 *
 * `auth('api')->login()` leaves the minted token in the process-global
 * `JWT::$token` singleton, and the parser falls back to it when the request
 * carries no usable cookie. So a test whose cookie stopped being transported
 * is still GREEN — it just answers out of memory. Every other mechanism in
 * this suite is blind to that by construction: the status code is right, the
 * assertion passes, the suite reports success. It is the one failure shape
 * that produces a green light with no light behind it.
 *
 * STRICT MODE removes the memory before every request
 * (`Tests\TestCase::call()`), which turns "is this test authenticated, or is it
 * answering out of memory?" from a code read into a whole-suite number. It
 * found the last class-C test in the migration — `SameOriginGuardTest`
 * called `withUnencryptedCookie()` and asserted a 403 the singleton produced,
 * and deleting its cookie call changed nothing.
 *
 * ## Why this file exists at all
 *
 * A measurement nothing runs is a comment. This file makes sure the switch is
 * wired to something real: that the environment variable is read, that the
 * override actually clears the state, and that exactly ONE class is exempt.
 * Both directions of that last number are guarded — "two classes need the
 * singleton" would mean the measurement no longer covers the suite, and
 * "nobody needs it" would mean the premise probe had been quietly deleted,
 * which is the same failure as a guard nobody reads.
 */
class JwtAuthStateStrictnessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A token, signed WITHOUT the `api` guard, so setting it up leaves the
     * guard itself alone and the assertion is about the singleton only.
     */
    private function tokenInTheSingleton(): string
    {
        $token = JWTAuth::fromUser(User::factory()->create());

        app('tymon.jwt')->setToken($token);
        app('auth')->forgetGuards();

        return $token;
    }

    public function test_the_switch_can_be_forced_both_ways_and_given_back_to_the_environment(): void
    {
        // Deliberately ambient-agnostic: this states something about the
        // WIRING, so it has to hold whether the suite is running with
        // `JWT_AUTH_STATE_STRICT=1` or without it.
        $ambient = self::strictAuthStateIsEnabled();

        self::forceStrictAuthState(! $ambient);

        $this->assertSame(
            ! $ambient,
            self::strictAuthStateIsEnabled(),
            'Forcing the switch must take effect over whatever the environment decided.'
        );

        self::forceStrictAuthState($ambient);

        $this->assertSame(
            $ambient,
            self::strictAuthStateIsEnabled(),
            'Restoring a value must take effect too — otherwise the first assertion only proved the cache was empty.'
        );

        // This is the third state, and the one `tearDown()` uses: no decision at
        // all, so the environment answers again. A test that flipped the switch
        // and forgot to hand it back would otherwise decide for every test that
        // runs after it in the same process.
        self::forceStrictAuthState(null);

        $this->assertSame(
            $ambient,
            self::strictAuthStateIsEnabled(),
            'Handing the switch back to the environment must reproduce the ambient value.'
        );
    }

    /**
     * The environment variable this class documents is the one the resolver
     * actually reads.
     *
     * The name is the entire contract with whoever wants to run the strict
     * suite — a CI job, a verifier, a person at a terminal. A constant that
     * got renamed while the resolver kept the old string would make the gate
     * quietly unreachable: `JWT_AUTH_STATE_STRICT=1 php artisan test` would run,
     * print a green suite, and measure nothing. That is the exact failure this
     * whole arrangement exists to prevent, so it is checked here.
     */
    public function test_the_documented_environment_variable_is_the_one_that_is_read(): void
    {
        $this->assertSame('JWT_AUTH_STATE_STRICT', TestCase::STRICT_AUTH_STATE_ENV);

        $bag = array_key_exists(TestCase::STRICT_AUTH_STATE_ENV, $_SERVER)
            ? $_SERVER[TestCase::STRICT_AUTH_STATE_ENV]
            : null;

        $ambient = self::strictAuthStateIsEnabled();

        try {
            $_SERVER[TestCase::STRICT_AUTH_STATE_ENV] = '1';
            self::forceStrictAuthState(null);

            $this->assertTrue(
                self::strictAuthStateIsEnabled(),
                'The resolver must read the variable named by TestCase::STRICT_AUTH_STATE_ENV.'
            );
        } finally {
            if ($bag === null) {
                unset($_SERVER[TestCase::STRICT_AUTH_STATE_ENV]);
            } else {
                $_SERVER[TestCase::STRICT_AUTH_STATE_ENV] = $bag;
            }

            self::forceStrictAuthState(null);
        }

        $this->assertSame(
            $ambient,
            self::strictAuthStateIsEnabled(),
            'Restoring the environment must put the switch back where it was.'
        );
    }

    /**
     * The resolver reads THREE sources, and the docblock in `TestCase` names all
     * three. Each is exercised ALONE here, with the other two cleared, so a
     * dropped source is a red test rather than a line that quietly stops
     * mattering.
     *
     * That is not a hypothetical: the comment used to promise `$_SERVER` and
     * `getenv()` while the code read `$_SERVER` and `$_ENV`, and nothing tested
     * EITHER — which is the only reason the disagreement could sit there. A
     * claim about a private resolver's fallbacks is only a map if somebody walks
     * the map.
     *
     * The three sources, and what each one is FOR:
     *  - `$_SERVER` — the documented `JWT_AUTH_STATE_STRICT=1 php artisan test`
     *    prefix (MEASURED: the CLI SAPI copies the environment here).
     *  - `$_ENV` — a PHPUnit `<env>` entry, which
     *    `PhpHandler::handleEnvVariables()` writes to this superglobal and NOT to
     *    `$_SERVER` (`PhpHandler.php:125-130` is `<server>` only).
     *  - `getenv()` — anything the other two never saw. On this host
     *    `variables_order` is `GPCS`, so `$_ENV` is provably empty for a CLI
     *    prefix; if the first source ever stopped carrying it, this is what
     *    still would.
     */
    public function test_each_of_the_three_sources_is_read_on_its_own(): void
    {
        $name = TestCase::STRICT_AUTH_STATE_ENV;

        $server = $_SERVER[$name] ?? null;
        $env = $_ENV[$name] ?? null;
        $processEnv = getenv($name);

        try {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            // 1. `$_SERVER` alone.
            $_SERVER[$name] = '1';
            self::forceStrictAuthState(null);
            $this->assertTrue(
                self::strictAuthStateIsEnabled(),
                'PREMISE: a value in $_SERVER alone must switch strict mode on — that is the documented CLI prefix.'
            );
            unset($_SERVER[$name]);

            // 2. `$_ENV` alone: what a PHPUnit `<env>` entry looks like.
            $_ENV[$name] = '1';
            self::forceStrictAuthState(null);
            $this->assertTrue(
                self::strictAuthStateIsEnabled(),
                'A value in $_ENV alone must switch strict mode on — that is the source PHPUnit writes for an <env> entry.'
            );
            unset($_ENV[$name]);

            // 3. The process environment alone: the source the docblock
            //    promised and the code did not read.
            putenv("{$name}=1");
            self::forceStrictAuthState(null);
            $this->assertTrue(
                self::strictAuthStateIsEnabled(),
                'A value that reached neither superglobal must still be read from getenv() — it is a listed source, and on a host with variables_order=GPCS it is the only one a PHP-side set() can reach.'
            );

            // And the negative, because a resolver that answers "on" to
            // everything is not a resolver. All three cleared at once.
            putenv($name);
            self::forceStrictAuthState(null);
            $this->assertFalse(
                self::strictAuthStateIsEnabled(),
                'With all three sources clear the switch must be off, or this test proves nothing about the sources.'
            );
        } finally {
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }

            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }

            $processEnv === false ? putenv($name) : putenv("{$name}={$processEnv}");

            self::forceStrictAuthState(null);
        }
    }

    /**
     * The switch actually clears the memory, and the request then has to
     * authenticate for itself.
     *
     * Both halves are asserted, because either alone would be satisfiable by a
     * broken implementation: clearing without a request says nothing about
     * whether the request needs it, and a request without clearing is exactly
     * the state STRICT MODE is supposed to rule out.
     */
    public function test_the_switch_clears_the_singleton_before_every_request(): void
    {
        $token = $this->tokenInTheSingleton();

        self::forceStrictAuthState(true);

        // The premise: without the switch, this very request is answered out of
        // memory. That is the false pass STRICT MODE exists to make impossible.
        self::forceStrictAuthState(false);

        $this->assertTrue($this->inMemoryJwtTokenIsSet(), 'PREMISE: the singleton must hold a token.');

        $this->getJson('/api/auth/me')->assertOk();

        self::forceStrictAuthState(true);

        $this->getJson('/api/auth/me')->assertUnauthorized();

        self::forceStrictAuthState(null);

        // The same token, back on the wire through the one legal channel:
        // nothing about the switch disabled authentication, it only removed the
        // shortcut.
        $this->withJwtCookie($token);

        $this->getJson('/api/auth/me')->assertOk();
    }

    /**
     * This class declines the exemption, and the base class defaults to
     * declining it too.
     *
     * The pair of facts is what makes the scan in
     * `test_exactly_one_class_is_exempt_from_the_strict_clearing` a real
     * statement: "exactly one" is only meaningful if the other classes are not
     * accidentally inheriting `true` from somewhere, and a base-class default of
     * `true` would silently exempt the entire suite.
     */
    public function test_the_base_class_and_this_class_both_decline_the_exemption(): void
    {
        $this->assertFalse(
            static::$answersRequestsFromTheInMemoryJwtToken,
            'A strictness test that exempted itself would be auditing nothing.'
        );

        $this->assertFalse(
            (new ReflectionClass(TestCase::class))->getStaticPropertyValue('answersRequestsFromTheInMemoryJwtToken'),
            'The base class must default to "no exemption". A `true` here would exempt the whole suite.'
        );
    }

    /**
     * Exactly one test class is allowed to answer out of memory — the premise
     * probe that proves the singleton is a genuine alternative source.
     *
     * Scanned over the whole suite rather than asserted for one known class, so
     * a NEW exemption cannot be added by editing a name in this file alone.
     * Two of them would mean the strict run no longer covers the suite; zero
     * would mean the probe was removed and the strict number stopped meaning
     * anything.
     */
    public function test_exactly_one_class_is_exempt_from_the_strict_clearing(): void
    {
        $exempt = [];

        foreach ($this->concreteTestClasses() as $class) {
            $reflection = new ReflectionClass($class);

            // `tests/Support/` holds traits and stubs that are not test cases at
            // all and have no such property. Only a concrete subclass of this
            // base class can make a request.
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(TestCase::class)) {
                continue;
            }

            if ($reflection->getStaticPropertyValue('answersRequestsFromTheInMemoryJwtToken') === true) {
                $exempt[] = $class;
            }
        }

        $this->assertSame(
            [JwtCookieChannelTest::class],
            $exempt,
            "Exactly ONE class may answer its requests from the in-memory JWT token, and it is the\n".
            "premise probe: without it, clearing the singleton would prove nothing. Two exemptions mean\n".
            'the strict run no longer covers the suite; none means the probe is gone.'
        );
    }

    /**
     * Every concrete test class in the suite, derived from the `tests/`
     * directory the rest of the guards scan.
     *
     * The class name is rebuilt from the path rather than taken from
     * `get_declared_classes()`: a run only loads the classes PHPUnit has
     * already reached, so the declared list is a snapshot of the test ORDER, not
     * of the suite.
     *
     * @return list<class-string>
     */
    private function concreteTestClasses(): array
    {
        $classes = [];

        /** @var \SplFileInfo $file */
        foreach (
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path('tests'), \FilesystemIterator::SKIP_DOTS)
            ) as $file
        ) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr(
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                strlen('tests/')
            );

            $class = 'Tests\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

            if (! class_exists($class)) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }
}
