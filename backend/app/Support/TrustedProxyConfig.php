<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolves which reverse proxies may set `X-Forwarded-*` and which headers are
 * honoured at all.
 *
 * Topology: Caddy terminates TLS and forwards to this backend over FastCGI
 * (`deployment/caddy-media-api-accel.Caddyfile`). Without an explicit trust
 * list Symfony IGNORES every `X-Forwarded-*` header, which means
 * `$request->isSecure()` is permanently `false` (every generated absolute URL
 * degrades to `http://`, e.g. in mails and PKPASS payloads) and every
 * rate limiter keyed on `$request->ip()` collapses all clients into the single
 * proxy bucket — one abusive client would 429 every tenant.
 *
 * Defaults to loopback, which covers the shipped compose topology (backend and
 * Caddy share the host network). Deployments where Caddy runs on another host
 * override it with `TRUSTED_PROXIES` (comma-separated IPs/CIDRs, or `*`/`**`
 * to trust the calling IP — only safe behind a proxy that overwrites the
 * headers itself).
 *
 * ## When this is read
 *
 * `config/security.php` is the single source of truth and the only place
 * `env()` is allowed, so the value exists only once the config repository is
 * bound (`LoadConfiguration`). `bootstrap/app.php` therefore installs the trust
 * list from an `$app->booting()` callback: the `withMiddleware()` callbacks are
 * invoked when the HTTP/console kernel is **resolved**, i.e. *before*
 * `LoadEnvironmentVariables` and `LoadConfiguration` have run, where neither
 * `config()` nor `.env` is available. Resolving there made a value an operator
 * sets in `.env` silently ineffective — the rate limiters keyed on
 * `$request->ip()` collapsed into the proxy bucket and `EnsureSameOrigin`
 * compared the `Origin` scheme against the `http` `getScheme()` under TLS,
 * rejecting every same-origin write request.
 */
final class TrustedProxyConfig
{
    /**
     * Trust-all sentinel understood by Laravel's TrustProxies middleware.
     */
    private const TRUST_ALL = ['*', '**'];

    /**
     * `TRUSTED_PROXIES` unset/empty: the shipped docker-compose topology, where
     * Caddy reaches the backend over the loopback interface.
     *
     * @var array<int, string>
     */
    private const DEFAULT_IPS = ['127.0.0.1', '::1'];

    /**
     * The trust list exactly as Laravel's `TrustProxies` middleware consumes
     * it.
     *
     * @return array<int, string>|string Trusted proxy IPs/CIDRs, or the
     *                                   trust-all sentinel `'*'`.
     */
    public static function ips(): array|string
    {
        $configured = config('security.trusted_proxies');

        if (! is_string($configured) || trim($configured) === '') {
            return self::DEFAULT_IPS;
        }

        $ips = array_values(array_filter(
            array_map(trim(...), explode(',', $configured)),
            static fn (string $ip): bool => $ip !== '',
        ));

        if ($ips === []) {
            return self::DEFAULT_IPS;
        }

        // The sentinel has to be the STRING `'*'`, not the one-element list
        // `['*']`: the middleware branches on `$trustedIps === '*'`, and a list
        // containing the literal is handed to Symfony as a CIDR, where `'*'` is
        // not a valid range and therefore trusts nothing at all.
        if (in_array($ips[0], self::TRUST_ALL, true)) {
            return self::TRUST_ALL[0];
        }

        return $ips;
    }

    /**
     * The `X-Forwarded-*` header set a TLS-terminating reverse proxy uses.
     *
     * Spelled out explicitly instead of relying on the framework default,
     * which additionally trusts `X-Forwarded-Prefix` and the
     * `X-Forwarded-Aws-Elb` bundle (`X-Forwarded-Client-Port`,
     * `-Proto`, `-Ssl`) — Caddy sets none of those, and a client that can reach
     * the backend directly could otherwise inject them.
     */
    public static function headers(): int
    {
        return Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO;
    }
}
