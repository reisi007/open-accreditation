<?php

namespace Tests\Feature;

use App\Support\TrustedProxyConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * WP-1-c: trusted reverse proxies.
 *
 * Caddy terminates TLS and forwards to this backend over FastCGI. Without an
 * explicit trust list Symfony ignores every `X-Forwarded-*` header, so
 * `$request->isSecure()` is permanently `false` (absolute URLs in mails and
 * PKPASS payloads degrade to `http://`) and every `$request->ip()`-keyed rate
 * limiter collapses all clients into the proxy's single bucket — one abusive
 * client would 429 every tenant.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A probe route that reports what the framework actually resolved for
        // the current request.
        Route::get('/__proxy-probe', fn (Request $request) => response()->json([
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'scheme' => $request->getScheme(),
            'url' => $request->url(),
            'root' => $request->root(),
        ]))->name('__proxy-probe');
    }

    protected function tearDown(): void
    {
        // `Request::setTrustedProxies()` is static — drop what the middleware
        // and the rate-limiter test installed so it cannot leak.
        Request::setTrustedProxies([], TrustedProxyConfig::headers());

        parent::tearDown();
    }

    public function test_forwarded_headers_from_a_trusted_proxy_are_honoured(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.7',
                'X-Forwarded-Proto' => 'https',
            ])
            ->getJson('http://localhost/__proxy-probe');

        $response->assertOk()
            ->assertJsonPath('secure', true)
            ->assertJsonPath('ip', '203.0.113.7')
            ->assertJsonPath('scheme', 'https');
    }

    public function test_forwarded_host_and_port_from_a_trusted_proxy_are_honoured(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.7',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'bundesliga.test',
                'X-Forwarded-Port' => '443',
            ])
            ->getJson('http://backend.internal/__proxy-probe');

        $response->assertOk()
            ->assertJsonPath('host', 'bundesliga.test')
            ->assertJsonPath('port', 443)
            ->assertJsonPath('url', 'https://bundesliga.test/__proxy-probe')
            // This is the concrete effect the review called out: generated
            // absolute URLs (mails, PKPASS) are `https://…` instead of `http://…`.
            ->assertJsonPath('root', 'https://bundesliga.test');
    }

    public function test_forwarded_headers_from_an_untrusted_source_are_ignored(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
            ->withHeaders([
                'X-Forwarded-For' => '203.0.113.7',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'bundesliga.test',
            ])
            ->getJson('http://localhost/__proxy-probe');

        $response->assertOk()
            ->assertJsonPath('secure', false)
            // A client that can reach the backend directly must not be able to
            // choose its own rate-limiter bucket.
            ->assertJsonPath('ip', '198.51.100.23')
            ->assertJsonPath('host', 'localhost')
            ->assertJsonPath('url', 'http://localhost/__proxy-probe');
    }

    public function test_rate_limiter_buckets_are_separated_per_forwarded_client(): void
    {
        // The concrete risk behind WP-1-c: without proxy trust every client
        // shares the proxy's ip bucket, so one abusive client 429s every
        // tenant. Two different forwarded client IPs must therefore produce two
        // different throttle signatures.
        Request::setTrustedProxies(['127.0.0.1'], TrustedProxyConfig::headers());

        $limitKey = static function (string $clientIp): string {
            $request = Request::create(
                'https://bundesliga.test/api/auth/login',
                'POST',
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => '127.0.0.1',
                    'HTTP_X_FORWARDED_FOR' => $clientIp,
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                ],
            );

            return RateLimiter::limiter('login')($request)->key;
        };

        $this->assertSame($limitKey('203.0.113.7'), $limitKey('203.0.113.7'));
        $this->assertNotSame($limitKey('203.0.113.7'), $limitKey('198.51.100.9'));
    }

    /* ------------------------------------------------------------------ */
    /* Configuration */
    /* ------------------------------------------------------------------ */

    public function test_default_trusted_proxies_are_loopback(): void
    {
        $this->assertSame(['127.0.0.1', '::1'], TrustedProxyConfig::ips());
    }

    public function test_trusted_proxies_are_env_configurable(): void
    {
        config(['security.trusted_proxies' => '10.0.0.5, 10.0.0.0/8 ,']);

        $this->assertSame(['10.0.0.5', '10.0.0.0/8'], TrustedProxyConfig::ips());
    }

    public function test_trusted_proxies_accept_a_trust_all_sentinel(): void
    {
        config(['security.trusted_proxies' => '**']);

        // The STRING, not `['*']`: the middleware branches on `$proxies === '*'`
        // and a list containing the literal is passed to Symfony as a CIDR,
        // where it matches nothing (see TrustedProxyEnvFileTest for the
        // behavioural pin).
        $this->assertSame('*', TrustedProxyConfig::ips());
    }

    public function test_blank_trusted_proxies_fall_back_to_loopback(): void
    {
        config(['security.trusted_proxies' => '  ,  ']);

        $this->assertSame(['127.0.0.1', '::1'], TrustedProxyConfig::ips());
    }

    public function test_trusted_header_set_is_exactly_the_tls_proxy_set(): void
    {
        // The framework default additionally contains
        // `HEADER_X_FORWARDED_PREFIX` (a dedicated bit, 32) and
        // `HEADER_X_FORWARDED_AWS_ELB` (a bundle of the FOR/PROTO/PORT bits,
        // 26) — Caddy sets neither, and a client able to reach the backend
        // directly could otherwise inject them.
        $headers = TrustedProxyConfig::headers();

        $this->assertSame(
            Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
            $headers,
        );
        $this->assertSame(0, $headers & Request::HEADER_X_FORWARDED_PREFIX);
        $this->assertSame(0, $headers & Request::HEADER_FORWARDED);
    }
}
