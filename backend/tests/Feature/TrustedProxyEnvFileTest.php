<?php

namespace Tests\Feature;

use App\Support\TrustedProxyConfig;
use Dotenv\Repository\RepositoryInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use ReflectionProperty;
use Tests\TestCase;

/**
 * WF-1-a: `TRUSTED_PROXIES` must be read from the **environment file**.
 *
 * The trust list is installed from an `$app->booting()` callback in
 * `bootstrap/app.php`, because `config/security.php` (the single source of
 * truth, the only place `env()` is allowed) only exists once the config
 * repository is bound in the `LoadConfiguration` bootstrapper.
 *
 * It used to be installed from the `withMiddleware()` callback instead. That
 * callback is invoked when the HTTP/console kernel is **resolved** — before
 * `LoadEnvironmentVariables` and `LoadConfiguration` — so `config()` was not
 * even bound there (`bound('config') === false`) and the `Env::get()` fallback
 * only ever saw the *process* environment, never `.env`. A value an operator
 * puts in `.env` was therefore silently ignored: the rate limiters keyed on
 * `$request->ip()` stayed collapsed into the proxy bucket and `EnsureSameOrigin`
 * compared the `Origin` scheme against the `http` `getScheme()` under TLS,
 * rejecting every same-origin write request with a 403.
 *
 * `TrustedProxyTest` only ever exercised `config()` overrides, which is why
 * this slipped through. These tests set the value the way an operator does — in
 * a dotenv file loaded by `LoadEnvironmentVariables` — and assert what the
 * framework then does with it. No database is involved, so a second application
 * instance can be booted without disturbing the SQLite `:memory:` schema of the
 * test's own instance.
 *
 * ## Process-environment quarantine
 *
 * `Illuminate\Support\Env` keeps ONE process-wide, IMMUTABLE dotenv repository,
 * and `LoadEnvironmentVariables` writes the parsed `.env` into the process
 * superglobals (`$_ENV`, `$_SERVER`, `putenv`). A throwaway `.env` therefore
 * leaks into every application created later in the same PHPUnit process: the
 * first test's `TRUSTED_PROXIES=10.20.0.7` was still in place when the next
 * test asserted the loopback default, and it also broke `TrustedProxyTest`
 * (which runs afterwards in the same process). `quarantineEnvironment()` clears
 * the managed keys and drops the cached repository before the throwaway boot;
 * `restoreEnvironment()` puts the whole thing back.
 */
class TrustedProxyEnvFileTest extends TestCase
{
    /**
     * The only variable these tests move between the process environment and a
     * dotenv file. Kept explicit so nothing else is ever touched.
     *
     * @var list<string>
     */
    private const MANAGED_KEYS = ['TRUSTED_PROXIES'];

    private ?string $envDirectory = null;

    private ?RepositoryInterface $originalRepository = null;

    /** @var array<string, mixed> */
    private array $originalEnv = [];

    /** @var array<string, mixed> */
    private array $originalServer = [];

    /** @var array<string, string|false> */
    private array $originalPutenv = [];

    private bool $environmentQuarantined = false;

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

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

        // `TrustProxies` keeps the trust list in static state, and
        // `Request::setTrustedProxies()` is static too — reset both so the
        // throwaway application cannot leak into later tests.
        TrustProxies::flushState();
        Request::setTrustedProxies([], TrustedProxyConfig::headers());

        parent::tearDown();
    }

    public function test_trusted_proxies_from_the_environment_file_reach_the_framework(): void
    {
        $app = $this->bootedApplicationWithEnvFile('TRUSTED_PROXIES="10.20.0.7, 10.0.0.0/8"');

        // Precondition: the value really came out of the dotenv file …
        $this->assertSame('10.20.0.7, 10.0.0.0/8', $app->make('config')->get('security.trusted_proxies'));
        $this->assertSame(['10.20.0.7', '10.0.0.0/8'], TrustedProxyConfig::ips());

        // … and the framework honours `X-Forwarded-*` from it. Before the fix
        // only the loopback default was installed, so the forwarded client ip
        // was ignored and every client shared the proxy's rate-limiter bucket.
        $this->assertSame('203.0.113.7', $this->resolvedClientIp($app, '10.20.0.7', '203.0.113.7'));
        $this->assertSame('198.51.100.9', $this->resolvedClientIp($app, '10.0.0.5', '198.51.100.9'));
    }

    public function test_a_trusted_proxy_from_the_environment_file_can_terminate_tls(): void
    {
        // The second half of the WF-1-a consequence: with the trust list stuck
        // on its default, `$request->getScheme()` stayed `http` under TLS, and
        // `EnsureSameOrigin` compared the `Origin` scheme against it — so EVERY
        // same-origin `Origin: https://…` write request was rejected with 403.
        $app = $this->bootedApplicationWithEnvFile('TRUSTED_PROXIES="10.20.0.7"');

        $request = $this->forwardedRequest('10.20.0.7', '203.0.113.7');

        $app->make(TrustProxies::class)->handle($request, static fn () => response('ok'));

        $this->assertTrue($request->isSecure());
        $this->assertSame('https', $request->getScheme());
    }

    public function test_trusted_proxies_from_the_environment_file_keep_the_loopback_default_out(): void
    {
        // A source that is NOT in the configured list must not be able to
        // pick its own rate-limiter bucket — the whole point of an explicit
        // list.
        $app = $this->bootedApplicationWithEnvFile('TRUSTED_PROXIES="10.20.0.7"');

        $this->assertSame('198.51.100.23', $this->resolvedClientIp($app, '198.51.100.23', '203.0.113.7'));
    }

    public function test_an_unset_environment_file_falls_back_to_the_loopback_default(): void
    {
        $app = $this->bootedApplicationWithEnvFile('# nothing here');

        $this->assertNull($app->make('config')->get('security.trusted_proxies'));
        $this->assertSame(['127.0.0.1', '::1'], TrustedProxyConfig::ips());
        $this->assertSame('203.0.113.7', $this->resolvedClientIp($app, '127.0.0.1', '203.0.113.7'));
        $this->assertSame('198.51.100.23', $this->resolvedClientIp($app, '198.51.100.23', '203.0.113.7'));
    }

    public function test_the_trust_all_sentinel_from_the_environment_file_is_honoured(): void
    {
        $app = $this->bootedApplicationWithEnvFile('TRUSTED_PROXIES="**"');

        // The STRING sentinel, not `['*']`: the middleware branches on
        // `$proxies === '*'` and a list containing the literal is handed to
        // Symfony as a CIDR, where `'*'` is not a valid range and therefore
        // trusts nothing at all.
        $this->assertSame('*', TrustedProxyConfig::ips());
        $this->assertSame('203.0.113.7', $this->resolvedClientIp($app, '198.51.100.23', '203.0.113.7'));
    }

    public function test_the_configured_header_set_is_installed_at_boot(): void
    {
        $app = $this->bootedApplicationWithEnvFile('TRUSTED_PROXIES="10.20.0.7"');

        $request = $this->forwardedRequest('10.20.0.7', '203.0.113.7', [
            'HTTP_X_FORWARDED_HOST' => 'bundesliga.test',
            'HTTP_X_FORWARDED_PORT' => '443',
        ]);

        $app->make(TrustProxies::class)->handle($request, static fn () => response('ok'));

        $this->assertSame('https', $request->getScheme());
        $this->assertSame('bundesliga.test', $request->getHost());
        $this->assertSame(443, $request->getPort());

        // The pinned header set must not grow: `X-Forwarded-Prefix` and the
        // `X-Forwarded-Aws-Elb` bundle are neither set by Caddy nor safe to
        // accept from a client that can reach the backend directly.
        $this->assertSame(
            TrustedProxyConfig::headers(),
            Request::getTrustedHeaderSet(),
        );
        $this->assertSame(0, Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_PREFIX);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Boot a throwaway application whose dotenv file holds the given content.
     *
     * `useEnvironmentPath()` is what makes this a genuine `.env` test: the file
     * is read by `LoadEnvironmentVariables` (via dotenv, into the immutable
     * repository), which is exactly the path an operator's value travels. The
     * value is deliberately NOT in the process environment — that is the whole
     * distinction the old `Env::get()` fallback could not see.
     *
     * The **console** kernel is bootstrapped on purpose: its bootstrapper list
     * is the one production also runs (`LoadEnvironmentVariables` →
     * `LoadConfiguration` → … → `BootProviders`, which is what fires the
     * `$app->booting()` callback that installs the trust list), and it carries
     * the `SetRequestForConsole` bootstrapper the HTTP kernel lacks (the HTTP
     * kernel binds `request` inside `sendRequestThroughRouter()` instead, which
     * `boot()` alone would never reach — the JWT provider resolves `request`
     * while booting and would fail with `Target class [request] does not
     * exist`).
     */
    private function bootedApplicationWithEnvFile(string $contents): Application
    {
        $this->quarantineEnvironment();

        $this->envDirectory = sys_get_temp_dir().'/accr-env-'.bin2hex(random_bytes(8));
        mkdir($this->envDirectory);
        file_put_contents($this->envDirectory.'/.env', $contents.PHP_EOL);

        $app = require base_path('bootstrap/app.php');
        $app->useEnvironmentPath($this->envDirectory);
        $app->make(Kernel::class)->bootstrap();

        // The fresh instance becomes the test's instance so `tearDown()` flushes
        // the container the assertions ran against (creating an application
        // re-points `Container::getInstance()` and the facade root at it).
        $this->app = $app;

        return $app;
    }

    /**
     * Clear the managed variables from the process environment and drop the
     * cached dotenv repository, so the next `LoadEnvironmentVariables` rebuilds
     * it from the throwaway `.env` instead of short-circuiting on the
     * (immutable) value the real `.env` already wrote.
     */
    private function quarantineEnvironment(): void
    {
        $this->originalRepository = $this->envRepository();
        $this->originalEnv = $_ENV;
        $this->originalServer = $_SERVER;
        $this->originalPutenv = [];

        foreach (self::MANAGED_KEYS as $key) {
            $this->originalPutenv[$key] = getenv($key);

            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        $this->setEnvRepository(null);
        $this->environmentQuarantined = true;
    }

    private function restoreEnvironment(): void
    {
        if (! $this->environmentQuarantined) {
            return;
        }

        $_ENV = $this->originalEnv;
        $_SERVER = $this->originalServer;

        foreach ($this->originalPutenv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key.'='.$value);
            }
        }

        $this->setEnvRepository($this->originalRepository);
        $this->originalRepository = null;
        $this->originalPutenv = [];
        $this->environmentQuarantined = false;
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

    /**
     * A request that arrives from `$remoteAddr` carrying `X-Forwarded-For:
     * $forwardedFor` and `X-Forwarded-Proto: https` — i.e. a TLS-terminating
     * reverse proxy in front of the backend.
     *
     * @param  array<string, string>  $extraServerVars
     */
    private function forwardedRequest(string $remoteAddr, string $forwardedFor, array $extraServerVars = []): Request
    {
        return Request::create('http://backend.internal/api/x', 'GET', [], [], [], [
            'REMOTE_ADDR' => $remoteAddr,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            ...$extraServerVars,
        ]);
    }

    /**
     * The client ip the framework resolves for a request that arrives from
     * `$remoteAddr` carrying `X-Forwarded-For: $forwardedFor`, after the
     * `TrustProxies` middleware has run — i.e. exactly what every
     * `$request->ip()`-keyed rate limiter uses as its bucket.
     */
    private function resolvedClientIp(Application $app, string $remoteAddr, string $forwardedFor): string
    {
        $request = $this->forwardedRequest($remoteAddr, $forwardedFor);

        $app->make(TrustProxies::class)->handle($request, static fn () => response('ok'));

        return (string) $request->ip();
    }
}
