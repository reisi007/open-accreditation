<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Origin guard for state-changing API requests (CSRF defence in depth).
 *
 * The SPA authenticates with the httpOnly JWT cookie and calls the API with
 * relative paths from the SAME origin (Caddy routes `/api*` to this backend
 * inside the very same per-mandant site block), so `SameSite=Lax` already
 * keeps the cookie away from cross-site requests. This middleware is the second
 * layer: it rejects `POST|PUT|PATCH|DELETE` whose `Origin` is missing, `null`
 * or does not belong to this request's own origin.
 *
 * Why the `Origin` header (and not `Referer`): per the Fetch standard an
 * `Origin` header is sent on EVERY non-`GET`/`HEAD` request — including
 * same-origin `fetch` — and is not suppressible by the attacker-controlled
 * page (only `null` via `Referrer-Policy: no-referrer`, which is rejected
 * here as well). A cross-site HTML form is therefore always detected.
 *
 * A MISSING `Origin` is rejected for every request that carries browser fetch
 * metadata (`Sec-Fetch-Site`/`-Mode`/`-Dest`) and for every deployment that
 * sets `security.require_origin_header`. Requests with neither an `Origin` nor
 * any `Sec-Fetch-*` header are non-browser API clients (curl, a mobile app, the
 * Playwright `APIRequestContext` the E2E suite uses for its setup calls) —
 * a browser page on another site cannot look like that, and such a client
 * cannot ride the victim's cookie jar. See `isBrowserRequest()`.
 *
 * `_method` override: Symfony's method override is enabled by the HTTP kernel
 * (`Request::enableHttpMethodParameterOverride()`), so a cross-site form can
 * reach a `PUT`/`PATCH`/`DELETE` route with a `POST` + `_method=PUT` body.
 * `$request->method()` already reports the EFFECTIVE method, and the method
 * check runs before anything else — the override cannot be used to slip past.
 *
 * **No route is exempt.** The routes that ESTABLISH a session
 * (`api/auth/login`, `api/auth/register`) need no existing cookie to be called,
 * which means `SameSite=Lax` does not protect them — an exemption there was a
 * pure weakening: a cross-site HTML form always carries `Origin` plus
 * `Sec-Fetch-*`, so the generic branch below rejects it either way (verified
 * before this list was removed: login with `Origin: https://evil.example`
 * answered 200, register 201 — signup CSRF, the victim uploads an accreditation
 * document into the attacker's account). `api/auth/activate/{token}` is a `GET`
 * and therefore inert for this guard.
 *
 * Laravel's own CSRF middleware skips unit tests and console requests; the
 * same escape hatch applies here so console commands, seeders and the test
 * suite are unaffected.
 *
 * Position: appended to the `api` group, i.e. it runs AFTER the
 * `auth:api` middleware (Laravel sorts `AuthenticatesRequests` implementations
 * to the front of the pipeline via the kernel's middleware priority). An
 * unauthenticated state-changing request is therefore answered with 401 before
 * the guard sees it — harmless, because without a session there is nothing to
 * abuse and no state to change. Every route that COULD be abused with a session
 * cookie is auth-gated, so for those the guard always runs.
 */
class EnsureSameOrigin
{
    /**
     * Methods that can change server state. `GET`/`HEAD` are safe by
     * definition (no CSRF-relevant side effect) and never carry an `Origin`
     * for same-origin requests, so they pass through untouched.
     */
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const DENIED_MESSAGE = 'Anfrage von fremder Herkunft abgelehnt.';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isStateChanging($request)) {
            return $next($request);
        }

        // Mirrors Laravel's VerifyCsrfToken: unit tests and console processes
        // have no browser `Origin` to check. The existing suite issues bare
        // POST/PUT/DELETE requests, so this also keeps them meaningful.
        if ($this->skipsOriginCheck()) {
            return $next($request);
        }

        $origin = $this->normalize($request->headers->get('origin'));

        if ($origin === null) {
            if ($this->isBrowserRequest($request) || config('security.require_origin_header')) {
                return $this->deny($request, 'origin_missing');
            }

            // Documented exception, NOT a blanket allow: a state-changing
            // request that carries neither `Origin` nor any `Sec-Fetch-*`
            // header cannot have been initiated by a browser page on another
            // site (see `isBrowserRequest()`), and a non-browser client cannot
            // ride the victim's cookie jar. It is a first-party API client —
            // curl, a mobile app, the Playwright `APIRequestContext` the E2E
            // suite uses for its setup calls. Set
            // `security.require_origin_header` to close even this case.
            return $next($request);
        }

        if ($origin === 'null') {
            // Sandboxed iframe, `Referrer-Policy: no-referrer` or a
            // privacy-stripping browser — no way to attribute the request.
            return $this->deny($request, 'origin_null');
        }

        if (! $this->isOwnOrigin($request, $origin)) {
            return $this->deny($request, 'origin_mismatch', $origin);
        }

        return $next($request);
    }

    /**
     * The EFFECTIVE request method (already resolved through the
     * `_method`/`X-HTTP-Method-Override` parameter).
     */
    private function isStateChanging(Request $request): bool
    {
        return in_array(strtoupper($request->method()), self::STATE_CHANGING_METHODS, true);
    }

    /**
     * Whether the `Origin` header describes the very origin this request was
     * sent to: same scheme, same host, same port. Host comparison alone is not
     * enough — `http://tenant.example.com` (plaintext, MITM-able) and
     * `https://tenant.example.com` share the host but are different origins.
     *
     * `local` is the single documented exception, in TWO shapes, both of which
     * are the Vite dev server: it serves the SPA on `http://localhost:5173` and
     * proxies `/api` here with `changeOrigin: true`, which rewrites the `Host`
     * header to the proxy target.
     *
     * 1. **Same loopback host, different port** — the target is
     *    `http://localhost:8000`, so the request arrives as
     *    `Host: localhost:8000` while the browser says `Origin:
     *    http://localhost:5173`. Scheme and host still match, only the port
     *    differs.
     * 2. **Loopback ALIAS, different host AND port** — CI sets
     *    `VITE_API_PROXY=http://127.0.0.1:8000`, so the request arrives as
     *    `Host: 127.0.0.1:8000` while the browser still says `Origin:
     *    http://localhost:5173`: two spellings of the very same loopback
     *    interface, which the strict host check rejected before the dev
     *    exception was ever reached (this blocked every login in the E2E stack).
     *
     * The alias exception is deliberately as narrow as possible: it requires
     * `local` AND a loopback `Origin` host AND a loopback request host. A
     * non-loopback origin therefore stays rejected in every environment
     * (`Origin: https://evil.example` + `Host: 127.0.0.1:8000` ⇒ 403), so the
     * cross-site hole this middleware exists to close cannot be reached by
     * "any host difference is fine in dev". Outside `local` the check stays
     * strict host+port, and the scheme check runs before all of it.
     */
    private function isOwnOrigin(Request $request, string $origin): bool
    {
        $given = $this->parseOrigin($origin);

        if ($given === null) {
            return false;
        }

        if ($given['scheme'] !== strtolower($request->getScheme())) {
            return false;
        }

        $requestHost = strtolower($request->getHost());

        if ($given['host'] !== $requestHost) {
            return $this->isLocalLoopbackAlias($given['host'], $requestHost);
        }

        if ($given['port'] === $request->getPort()) {
            return true;
        }

        return app()->environment('local');
    }

    /**
     * The `local` exception for the Vite dev proxy answering under a different
     * loopback SPELLING (`localhost` ⇄ `127.0.0.1` ⇄ `::1`) than the one the
     * browser used: accepted only in `local` and only when BOTH sides are
     * loopback. Loopback is unreachable from the network, so an origin on it can
     * only have been opened by a process on the developer's own machine — there
     * is no attacker-controlled site to be re-anchored onto this app, and the
     * request still has to reach a loopback `Host` itself.
     */
    private function isLocalLoopbackAlias(string $originHost, string $requestHost): bool
    {
        return app()->environment('local')
            && $this->isLoopbackHost($originHost)
            && $this->isLoopbackHost($requestHost);
    }

    /**
     * Whether a host is the loopback interface — a fixed, name-free allow-list
     * rather than a DNS lookup (which an attacker could steer, and which could
     * change between this check and the connect). `localhost`, the whole
     * `127.0.0.0/8` block and the IPv6 loopback (any spelling of it, incl. the
     * IPv4-mapped `::ffff:127.0.0.1`) qualify; every public name, LAN address
     * and foreign tenant domain does not.
     */
    private function isLoopbackHost(string $host): bool
    {
        // `parse_url()` keeps the brackets of an IPv6 literal (`http://[::1]:5173`
        // → host `[::1]`), while `Request::getHost()` already strips them.
        $host = strtolower(trim($host));
        $host = trim($host, '[]');

        if ($host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $packed = inet_pton($host);

        if ($packed === false) {
            return false;
        }

        // IPv4: the entire 127.0.0.0/8 block is loopback.
        if (strlen($packed) === 4) {
            return $packed[0] === "\x7f";
        }

        // IPv6: only `::1` and its IPv4-mapped form. `inet_pton()` normalises
        // every equivalent spelling (`0:0:0:0:0:0:0:1`) to the same bytes.
        return $packed === inet_pton('::1') || $packed === inet_pton('::ffff:127.0.0.1');
    }

    /**
     * Split an origin into comparable parts, defaulting the port to the
     * scheme's well-known one (`https://host` carries no explicit port but is
     * port 443). Returns null for anything that is not an absolute http(s)
     * origin — including a value that smuggles a path, query, fragment or
     * userinfo component, none of which a real `Origin` header ever contains
     * (`https://tenant.example/anything` is not this origin).
     *
     * @return array{scheme: string, host: string, port: int}|null
     */
    private function parseOrigin(string $origin): ?array
    {
        $parts = parse_url($origin);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80),
        ];
    }

    /**
     * Whether the request carries browser-only fetch metadata.
     *
     * Every browser sends `Sec-Fetch-Site` on EVERY request (Chrome 76+,
     * Firefox 90+, Safari 16.4+), and per the Fetch standard always sends
     * `Origin` on a non-`GET`/`HEAD` request. A state-changing request that
     * has NEITHER therefore cannot have been initiated by a page on another
     * site: a cross-site form/`fetch` is a browser request and would carry
     * both. Such a request comes from a non-browser API client.
     */
    private function isBrowserRequest(Request $request): bool
    {
        return $request->headers->has('sec-fetch-site')
            || $request->headers->has('sec-fetch-mode')
            || $request->headers->has('sec-fetch-dest');
    }

    /**
     * Trimmed, lower-cased `Origin` header value, or null when absent/empty.
     */
    private function normalize(mixed $origin): ?string
    {
        if (! is_string($origin)) {
            return null;
        }

        $origin = strtolower(trim($origin));

        return $origin === '' ? null : $origin;
    }

    /**
     * 403 with a stable, information-free message. The reason is logged
     * instead of being exposed: a split deployment is an operator problem, not
     * something an attacker should learn about from the response body.
     */
    private function deny(Request $request, string $reason, ?string $origin = null): JsonResponse
    {
        Log::notice('EnsureSameOrigin: state-changing request rejected.', [
            'reason' => $reason,
            'path' => $request->path(),
            'method' => $request->method(),
            'origin' => $origin,
            'host' => $request->getHost(),
        ]);

        return response()->json(['message' => self::DENIED_MESSAGE], Response::HTTP_FORBIDDEN);
    }

    /**
     * HTTP middleware never runs in a pure CLI process, but PHPUnit does —
     * and the test suite talks to the API without browser `Origin` headers.
     * Tests that want to exercise the guard flip this off explicitly
     * (`detectEnvironment('production')` + `setRunningInConsole(false)`), the
     * same way `TrustHostsTest` simulates a production request.
     */
    private function skipsOriginCheck(): bool
    {
        return app()->runningInConsole() || app()->environment('testing');
    }
}
