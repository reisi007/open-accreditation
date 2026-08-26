<?php

namespace App\Http\Middleware;

use App\Support\MandantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MandantContextMiddleware
{
    /**
     * P1a-B2: the Vite dev server port. The local-env Referer fallback accepts
     * `localhost:{port}` AND any `*.localhost:{port}` subdomain (RFC 6761
     * reserves the whole "localhost" TLD to the local device). The port is
     * significant — Symfony and `parse_url` keep it in the origin, only
     * getHost() strips it.
     */
    private const VITE_DEV_PORT = '5173';

    /**
     * Resolve the mandant from the request host and set it as the current
     * mandant.
     *
     * The `/up` health endpoint is public and must never require a mandant
     * (load balancers and CI probes hit it without a matching host), so it is
     * short-circuited before host resolution — production-safe.
     *
     * An unknown host results in a 404 — except in test/CLI contexts (PHPUnit
     * runs as a console process), where we continue without a mandant because
     * tests set the mandant manually via MandantContext::set(). In local and
     * testing environments a loopback host (e.g. `php artisan serve` on
     * 127.0.0.1 or a forwarded local port) falls back to the primary mandant
     * instead of 404, so dev servers and CI probes never hit the 404 path.
     * P3e-B3: this includes `*.localhost` subdomains — RFC 6761 reserves the
     * whole "localhost" TLD to the local device, so Vite dev-server origins
     * like `foo.localhost:5173` resolve to the primary mandant in dev.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->path() === 'up') {
            return $next($request);
        }

        $host = $this->resolveHost($request);

        if ($host !== '') {
            $mandant = MandantContext::resolve($host);

            if ($mandant !== null) {
                MandantContext::set($mandant);

                return $next($request);
            }

            if ($this->isLoopback($host) && app()->environment('local', 'testing')) {
                MandantContext::set(MandantContext::default());

                return $next($request);
            }

            if (! $this->skipsUnknownMandant()) {
                abort(404, 'Mandant not found');
            }
        }

        return $next($request);
    }

    /**
     * The request host, lower-cased. In local dev the Vite proxy rewrites the
     * Host header — the Referer header (sent automatically by browsers) still
     * carries the original host, so we prefer it (mirrors the portal's
     * BrandContextMiddleware). P1a-B2: ONLY the Vite dev origin
     * (`localhost:5173`) and any `*.localhost:5173` subdomain are accepted as
     * Referer host — a foreign Referer (spoofable via any request) must never
     * steer host resolution; anything else falls back to the Host-header path.
     *
     * The `*.localhost` subdomains are per-mandant Vite dev origins (RFC 6761
     * reserves the whole "localhost" TLD to the local device). They resolve to
     * the primary mandant via the loopback fallback in `handle()`.
     */
    private function resolveHost(Request $request): string
    {
        if (app()->environment('local')) {
            $referer = parse_url((string) $request->header('referer', ''));

            if (is_array($referer) && isset($referer['host']) && $referer['host'] !== '') {
                $refererHost = strtolower((string) $referer['host'])
                    .(isset($referer['port']) ? ':'.$referer['port'] : '');

                if ($this->isViteDevOrigin($refererHost)) {
                    return $refererHost;
                }
            }
        }

        return strtolower($request->getHost());
    }

    /**
     * Whether the Referer host is a trusted Vite dev-server origin: exact
     * `localhost:{port}` or any `*.localhost:{port}` subdomain. Consulted ONLY
     * in the `local` environment — production keeps strict host resolution.
     */
    private function isViteDevOrigin(string $refererHost): bool
    {
        $suffix = '.localhost:'.self::VITE_DEV_PORT;

        return $refererHost === 'localhost:'.self::VITE_DEV_PORT
            || str_ends_with($refererHost, $suffix);
    }

    /**
     * Whether the host behaves like loopback for the local/testing fallback:
     * localhost / IPv4 / IPv6 loopback plus any `*.localhost` subdomain.
     * P3e-B3: RFC 6761 reserves the whole "localhost" TLD to the local
     * device, so the Vite dev server's per-mandant origins
     * (`foo.localhost:5173`) must resolve to the primary mandant instead of
     * failing host resolution. An optional port suffix is tolerated (Symfony
     * and `parse_url` keep it, only the hostname comparison strips it).
     * Consulted ONLY in the `local`/`testing` environments — production keeps
     * strict host resolution.
     */
    private function isLoopback(string $host): bool
    {
        $hostname = parse_url('http://'.strtolower(trim($host)), PHP_URL_HOST)
            ?? strtolower(trim($host));

        if (in_array($hostname, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        return str_ends_with($hostname, '.localhost');
    }

    /**
     * HTTP middleware never executes in a pure CLI process, but PHPUnit runs
     * as a console process — tests without a resolvable host (e.g. an
     * unseeded `localhost`) must not 404; they set the mandant manually.
     */
    private function skipsUnknownMandant(): bool
    {
        return app()->runningInConsole() || app()->environment('testing');
    }
}
