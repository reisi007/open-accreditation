<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use PHPOpenSourceSaver\JWTAuth\Http\Parser\Cookies;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // F2: restrict the JWT token parser chain to the httpOnly `accr_jwt`
        // cookie ONLY. The package default chain is `[AuthHeaders, QueryString,
        // InputSource]` (AbstractServiceProvider::registerTokenParser) plus
        // `[RouteParams, Cookies]` appended by LaravelServiceProvider::boot() —
        // i.e. it also accepts `Authorization: Bearer`, `?token=`, POST `token`
        // and route-param tokens. This provider boots after the package's
        // provider (package-discovery providers register before the
        // bootstrap/providers.php entries), so `setChain` replaces the whole
        // chain with just the cookie parser. The SPA authenticates exclusively
        // via the cookie, so every other channel is dead surface for token
        // exfiltration (e.g. tokens leaked into logs or referers).
        app('tymon.jwt.parser')->setChain([
            (new Cookies((bool) config('jwt.decrypt_cookies')))
                ->setKey((string) config('jwt.cookie_key_name')),
        ]);

        // B2: separate throttle buckets for login and register. Before, both
        // routes shared a single `throttle:5,1` bucket, so failed register
        // attempts silently consumed the login quota and vice versa. Each
        // route now uses its own named limiter (`throttle:login` /
        // `throttle:register`); the explicit `by()` key guarantees the
        // middleware resolves distinct cache keys for the same ip (without a
        // key it falls back to the route+ip signature shared by both routes).
        // Both are keyed on the ip only — the previous per-authenticated-user
        // branch on `login` was dead code, because the request user is never
        // resolved at middleware time during login; per-ip is the real
        // brute-force protection. Register is per-ip too.
        // B2-Floor (login/register): limits are env-dependent. In `local` and
        // `testing` the budgets are development floors — the parallel Playwright
        // suite runs ~8 workers behind ONE ip and needs ~17 logins/min on
        // @feature:accreditation (approvals.spec.ts setup helper), and register
        // creates several users concurrently. In `production` the real
        // brute-force values apply: login 15/min, register 10/min.
        $loginLimit = app()->environment('local', 'testing') ? 40 : 15;
        $registerLimit = app()->environment('local', 'testing') ? 30 : 10;
        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute($loginLimit)
            ->by('login:'.$request->ip()));
        RateLimiter::for('register', static fn (Request $request): Limit => Limit::perMinute($registerLimit)
            ->by('register:'.$request->ip()));

        // P3b-F1: applying for accreditations throttles per authenticated user
        // (a scripted flood of applications across many accreditations is the
        // threat — quota is not enforced at apply time), falling back to per-ip
        // for unauthenticated requests.
        RateLimiter::for('apply', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('apply:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // P3e-B1: named limiters for the remaining public/anonymous inline
        // throttles. Inline `throttle:20,1` / `throttle:60,1` all resolve to a
        // single shared per-ip bucket — Laravel keys them on
        // `sha1(domain|ip)`, and without a route domain that signature is
        // identical for every route. `activate` (20,1), the portal (60,1) and
        // the accreditation list (60,1) therefore cannibalized each other's
        // budget, so a parallel Playwright run hit a 429 on `activate`. Named
        // limiters with explicit `by()` keys give each surface its own bucket:
        // activation links (30/min per ip) and the public portal / accreditation
        // reads. The public budget is env-dependent like the login/register
        // floors: in `local`/`testing` it is raised to 300/min — the ui-review
        // screenshot suite (2 parallel workers, ONE ip) deterministically
        // exhausted the old 60/min mid-run (70-GET burst returned exactly
        // 60×200 then 10×429). In `production` the real per-ip value (60/min)
        // applies.
        RateLimiter::for('activate', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('activate:'.$request->ip()));
        $publicLimit = app()->environment('local', 'testing') ? 300 : 60;
        RateLimiter::for('public', static fn (Request $request): Limit => Limit::perMinute($publicLimit)
            ->by('public:'.$request->ip()));

        // P4-F3: the QR-verification scan endpoint (`/api/verify/*`) gets its
        // OWN named limiter instead of riding the shared `public` bucket. Before,
        // scanning throttling was coupled to the unrelated portal and
        // accreditation-read traffic — a burst of scans silently consumed the
        // public read budget and vice versa. The dedicated bucket is keyed
        // per-ip exactly like `public` (mirrors its env-dependent floor: 300/min
        // in `local`/`testing` so the ui-review screenshot suite and parallel
        // Playwright scans don't trip a 429, 60/min in `production`).
        $verifyLimit = app()->environment('local', 'testing') ? 300 : 60;
        RateLimiter::for('verify', static fn (Request $request): Limit => Limit::perMinute($verifyLimit)
            ->by('verify:'.$request->ip()));

        // F5: user-media uploads throttle per authenticated user (a scripted
        // upload flood of portraits/press-ids/attachments is the threat), key
        // `media:{userId}`; unauthenticated fallback `media:{ip}`. The explicit
        // `user('api')` is required: `Request::user()` resolves the *default*
        // guard (web/session), which is null for API requests — the existing
        // `apply` limiter above has that latent per-ip fallback, these new
        // limiters must not repeat it.
        RateLimiter::for('media', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('media:'.($request->user('api')?->getAuthIdentifier() ?? $request->ip())));

        // P2a-RL: admin WRITE routes (POST/PUT/DELETE under /api/admin/*) are
        // throttled per authenticated admin user (key `admin:{userId}`,
        // fallback `admin:{ip}`) with a generous 300/min budget. The admin
        // GET/read routes stay unthrottled — browsing lists is auth-gated
        // already and a shared bucket would harm legitimate admin usage.
        RateLimiter::for('admin', static fn (Request $request): Limit => Limit::perMinute(300)
            ->by('admin:'.($request->user('api')?->getAuthIdentifier() ?? $request->ip())));

        // P5-F2: pass-resend per admin user (key `resend:{userId}`, fallback
        // `resend:{ip}`) — closes the mail-spam vector (10/min), far stricter
        // than the shared `admin` write budget.
        RateLimiter::for('resend', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('resend:'.($request->user('api')?->getAuthIdentifier() ?? $request->ip())));
    }
}
