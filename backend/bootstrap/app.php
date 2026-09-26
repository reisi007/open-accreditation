<?php

use App\Http\Middleware\EnsureMandantMembership;
use App\Http\Middleware\EnsureSameOrigin;
use App\Http\Middleware\MandantContextMiddleware;
use App\Support\MandantContext;
use App\Support\TrustedProxyConfig;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // B3: host-header allow-list. Symfony validates every request Host
        // against these patterns and rejects foreign hosts (400) before any
        // route/mandant logic runs — MandantContextMiddleware's unknown-host
        // 404 is an additional layer for hosts that pass the allow-list but
        // map to no mandant. Entries are regexes (Symfony wraps each in
        // `{...}i`): plain hostnames match exactly, `*.test`-style wildcards
        // must be spelled as `^(.+\.)?test$`.
        //
        // Three hardening rules (WP-1-d):
        //  1. The dev wildcards (`*.test`, `*.localhost`) ship ONLY outside
        //     production — they would otherwise hand a production deployment a
        //     wildcard in its allow-list.
        //  2. Loopback (`localhost`, `127.0.0.1`, `::1`) is ALWAYS merged in,
        //     independently of `TRUSTED_HOSTS` and of the environment: a
        //     container `HEALTHCHECK`/LB probe hits `GET /up` from 127.0.0.1,
        //     and a host allow-list that dropped it answers 400 and marks the
        //     container unhealthy (restart loop) — exactly in the hardened
        //     configuration a non-empty `TRUSTED_HOSTS` produces. A loopback
        //     Host is not a tenant, so it can never reach mandant logic.
        //     A non-empty `TRUSTED_HOSTS` therefore REPLACES only the dev
        //     wildcards, so the operator still states exactly which static
        //     non-loopback hosts are allowed; the mandant hostnames are always
        //     merged in on top.
        //  3. A database failure must never silently degrade to an allow-list
        //     without a single real tenant domain: in production that turns one
        //     transient DB blip into a 400 for every tenant. Non-production
        //     keeps the built-in defaults (console, install, first boot); in
        //     production the failure is logged and turned into a loud 500.
        //     The hostname list itself is cached (MandantContext::hostnames()),
        //     so a warm cache keeps serving during a database outage.
        //  4. `$subdomains = false` (WF-2-c): the framework otherwise APPENDS
        //     `^(.+\.)?<APP_URL host>$` to the list — in production too. That
        //     is exactly the wildcard class rules 1 and 2 exclude, and it is
        //     invisible in the resolved config: the operator never wrote it.
        //     The bounded impact was limited to `GET /up` (every other route
        //     is behind MandantContextMiddleware, which still 404s an
        //     unknown subdomain), but the allow-list must be exactly what is
        //     declared here, so the subdomain expansion stays off.
        $middleware->trustHosts(function (): array {
            $patterns = ['localhost', '127.0.0.1', '^\[::1\]$'];

            $configured = config('security.trusted_hosts');

            if (is_string($configured) && trim($configured) !== '') {
                $patterns = array_merge($patterns, array_values(array_filter(
                    array_map(trim(...), explode(',', $configured)),
                    static fn (string $pattern): bool => $pattern !== '',
                )));
            } elseif (! app()->environment('production')) {
                $patterns[] = '^(.+\.)?test$';
                $patterns[] = '^(.+\.)?localhost$';
            }

            $hostnames = MandantContext::hostnames();

            if ($hostnames === null && app()->environment('production')) {
                Log::error('trustHosts: the mandant domain list is unavailable and TRUSTED_HOSTS is not set — refusing to serve with an allow-list that contains no tenant domain.', [
                    'app_env' => app()->environment(),
                ]);

                abort(500, 'Mandant domains are temporarily unavailable.');
            }

            foreach ($hostnames ?? [] as $hostname) {
                $patterns[] = preg_quote($hostname, '{}');
            }

            return array_values(array_unique($patterns));
        }, subdomains: false);

        $middleware->append(MandantContextMiddleware::class);
        // WP-1-b: CSRF defence in depth — every state-changing API request
        // must carry an `Origin` belonging to the request's own origin. No
        // route is exempt: the routes that ESTABLISH a session (login/register/
        // activate) need no cookie to be called, so `SameSite=Lax` does not
        // protect them, and a cross-site browser form always carries `Origin` +
        // `Sec-Fetch-*`, which the generic branch rejects anyway. Skipped in
        // console/unit tests, like Laravel's own VerifyCsrfToken.
        $middleware->api(append: [EnsureSameOrigin::class]);
        // #6-1: mandant membership per request. The JWT carries no mandant
        // claim (`User::getJWTCustomClaims()` is empty) and the only mandant
        // check in the auth flow ran at LOGIN time, so a token minted on
        // `a.example` replayed with `Host: b.example` passed `auth:api` and
        // reached the un-gated write routes of the `auth:api` group — apply
        // created an application inside the foreign mandant, media uploads
        // landed in the foreign mandant's storage namespace. This middleware
        // re-checks, per request, that the authenticated account holds a role
        // in the mandant the host resolved to (global `super_admin` excepted),
        // and supplements — never replaces — the per-resource `forMandant()`
        // scoping.
        //
        // Position: appended to the `api` group (so it is inherited by every
        // api route) AND spliced into the middleware priority list right
        // after `SubstituteBindings`, which is what actually makes it run as
        // the last middleware before the controller: after `auth:api` (it
        // needs the resolved user), after every route-specific rate limiter,
        // before any mandant-scoped mutation. Inert for routes without
        // `auth:api` (public portal, public accreditation list, QR `verify`,
        // login/register/activate) and for requests without a resolved
        // mandant (console/CLI, tests) — see the class docblock.
        $middleware->api(append: [EnsureMandantMembership::class]);
        $middleware->appendToPriorityList(
            SubstituteBindings::class,
            EnsureMandantMembership::class,
        );

        // This is an API-only SPA backend: guests must never be redirected to
        // a `login` HTML route (which does not exist). Unauthenticated requests
        // render as 401 JSON for api/* (see shouldRenderJsonWhen below), or a
        // 401 no-content response otherwise.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// R-D2: Caddy terminates TLS and forwards over FastCGI. Trusting it makes
// `$request->isSecure()` reflect `X-Forwarded-Proto` (absolute URLs in
// mails/PKPASS stay `https://`) and `$request->ip()` the real client — without
// it every ip-keyed rate limiter (login, register, admin, media) collapses all
// clients into the proxy's single bucket. The trusted header set is pinned
// explicitly in TrustedProxyConfig: the framework default would also honour
// `X-Forwarded-Prefix` and the `X-Forwarded-Aws-Elb` bundle, which Caddy never
// sets.
//
// This is `$app->booting()` and deliberately NOT `$middleware->trustProxies()`:
// the `withMiddleware()` callback above is invoked when the HTTP/console kernel
// is RESOLVED — before `LoadEnvironmentVariables` and `LoadConfiguration` have
// run — so `config('security.trusted_proxies')` is not bound yet and a
// `TRUSTED_PROXIES` value from `.env` was read from the process environment
// only, i.e. silently ignored. `booting` callbacks run inside `Application::boot()`,
// after the config repository exists, so `.env` is authoritative.
// `TrustProxies::at()`/`withHeaders()` are the very calls
// `Middleware::trustProxies()` makes; they store static state that the
// `TrustProxies` middleware reads per request.
$app->booting(function (): void {
    TrustProxies::at(TrustedProxyConfig::ips());
    TrustProxies::withHeaders(TrustedProxyConfig::headers());
});

return $app;
