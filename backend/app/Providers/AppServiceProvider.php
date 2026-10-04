<?php

namespace App\Providers;

use App\Queue\Failed\MandantAwareFailedJobProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use PHPOpenSourceSaver\JWTAuth\Http\Parser\Cookies;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Header a TEST client uses to name the test actor it speaks for.
     *
     * Honoured in `local` and `testing` ONLY — see
     * {@see self::throttleKeyFor()} for the contract and the reasoning. The
     * E2E harness sends this header on its own API traffic
     * (`frontend/tests/e2e/helpers/throttle-actor.ts`).
     */
    public const TEST_ACTOR_HEADER = 'X-Test-Actor';

    /**
     * Longest accepted actor value, and the ONLY characters accepted:
     * `[A-Za-z0-9._-]`. Anything else is discarded and the request falls back
     * to the plain per-ip key — i.e. the strict, un-split bucket.
     *
     * ## Why the anchor is `\z` and not `$`
     *
     * In PCRE `$` matches at the end of the subject **and before a trailing
     * newline**; `\z` matches only at the very end. So `/^[A-Za-z0-9._-]{1,32}$/`
     * accepts `"w1\n"`, and `AppServiceProvider::throttleKeyFor()` would put the
     * WHOLE string — newline included — into the cache key. The bucket name stays
     * unguessable per actor, so this is hygiene rather than a hole, but the JS
     * side disagreed: JavaScript's `$` (without `/m`) already means `\z`, so the
     * harness read a STRICTER rule than the provider applied, and the
     * cross-language test in `frontend/tests/e2e/throttle-actor.test.ts` read the
     * pattern from here and silently inherited the difference. `\z` makes both
     * sides say the same thing; that file translates `\z` → `$` for JS, because
     * `new RegExp('\\z')` is a literal `z` in JavaScript, not an anchor.
     */
    private const TEST_ACTOR_PATTERN = '/^[A-Za-z0-9._-]{1,32}\z/';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Position 45 (2026-10-02): `failed_jobs` records the owning mandant,
        // so the dead-letter admin surface can be mandant-isolated. The
        // framework builds `queue.failer` from `config/queue.php`; this
        // replaces the `database-uuids` provider with a subclass that stamps
        // `mandant_id` after the row is written. Every other driver (file,
        // null, dynamodb) is left untouched — the callback returns the
        // provider it was handed.
        $this->app->extend('queue.failer', function ($provider) {
            $config = $this->app['config']['queue.failed'];

            if (($config['driver'] ?? null) !== 'database-uuids') {
                return $provider;
            }

            return new MandantAwareFailedJobProvider(
                $this->app['db'],
                $config['database'],
                $config['table'],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        self::assertProductionAppKeyIsStrong();

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
        // Both are keyed on the ip — the previous per-authenticated-user
        // branch on `login` was dead code, because the request user is never
        // resolved at middleware time during login; per-ip is the real
        // brute-force protection. Register is per-ip too. (In `local`/`testing`
        // the ip is joined by the test actor, Position 49 — see
        // `throttleKeyFor()`; in every other environment the key is the plain
        // `'{bucket}:{ip}'` this comment describes.)
        // B2-Floor (login/register): limits are env-dependent. In `local` and
        // `testing` the budgets are development floors — the parallel Playwright
        // suite runs ~8 workers behind ONE ip and needs ~17 logins/min on
        // @feature:accreditation (approvals.spec.ts setup helper), and register
        // creates several users concurrently. In `production` the real
        // brute-force values apply: login 15/min, register 10/min.
        //
        // Position 49: a raised budget is the WEAKER of the two admissible
        // answers to a per-ip quota. The rate problem is that every worker of a
        // suite shares ONE ip, so the bucket is shared at EVERY worker count —
        // a bigger number only moves the wall (skill `playwright-parallel`,
        // „Lock vs. rate limiter“: rate is fixed by a throttle key per
        // worker/test actor, never by a worker count and never by a lock). The
        // per-actor split therefore lives in the KEY, not in the budget:
        // `throttleKeyFor()` below appends the test actor in `local`/`testing`
        // only. The floors stay as the headroom for clients that send no actor
        // at all (plain curl, a serial run, the screenshot suite).
        $loginLimit = app()->environment('local', 'testing') ? 40 : 15;
        $registerLimit = app()->environment('local', 'testing') ? 30 : 10;
        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute($loginLimit)
            ->by(self::throttleKeyFor($request, 'login')));
        RateLimiter::for('register', static fn (Request $request): Limit => Limit::perMinute($registerLimit)
            ->by(self::throttleKeyFor($request, 'register')));

        // P3b-F1: applying for accreditations throttles per authenticated user
        // (a scripted flood of applications across many accreditations is the
        // threat — quota is not enforced at apply time), falling back to per-ip
        // for unauthenticated requests.
        RateLimiter::for('apply', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('apply:'.($request->user('api')?->getAuthIdentifier() ?? $request->ip())));

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
            ->by(self::throttleKeyFor($request, 'activate')));
        $publicLimit = app()->environment('local', 'testing') ? 300 : 60;
        RateLimiter::for('public', static fn (Request $request): Limit => Limit::perMinute($publicLimit)
            ->by(self::throttleKeyFor($request, 'public')));

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
            ->by(self::throttleKeyFor($request, 'verify')));

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

    /**
     * The rate-limit key of an ip-keyed bucket, with the test-actor carve-out.
     *
     * ## The problem this solves (Position 49)
     *
     * The ip-keyed buckets — `login`, `register`, `activate`, `public`,
     * `verify` — count per client ip, and a test suite has ONE ip no matter how
     * many workers it runs. Every worker therefore spends from the same
     * counter, so the suite's request rate against a single endpoint grows
     * linearly with the worker count while the budget stays fixed: a shared CI
     * address is a shared bucket at EVERY worker count (skill
     * `playwright-parallel`, „IP-based throttle via worker count“). Neither
     * fewer workers nor a lock fixes that — a lock serialises, it does not
     * throttle, so the sequential lane reaches the same requests-per-minute,
     * only slower.
     *
     * ## What the key looks like
     *
     * | environment | header      | key                    |
     * |-------------|-------------|------------------------|
     * | any         | absent      | `{bucket}:{ip}`        |
     * | `local`/`testing` | malformed | `{bucket}:{ip}`   |
     * | `local`/`testing` | `w3-p1234` | `{bucket}:{ip}@w3-p1234` |
     * | everything else | any   | `{bucket}:{ip}`        |
     *
     * The ip stays in the key in every case, so a bucket is still attributable
     * to an address while debugging, and two actors behind DIFFERENT addresses
     * still do not share a bucket.
     *
     * ## Security contract (this is an auth path — read before changing it)
     *
     * 1. **Production behaviour is byte-identical.** Outside `local`/`testing`
     *    the method returns the very string the previous inline
     *    `'{bucket}:'.$request->ip()` returned. Pinned by
     *    `tests/Feature/RateLimitTestActorKeyTest`, in
     *    `test_the_actor_header_is_ignored_in_production` (byte for byte, plus
     *    equal to the no-header form) and
     *    `test_the_actor_header_does_not_split_the_bucket_in_production_over_http`
     *    (a DIFFERENT actor name on the same ip is still throttled — the
     *    discriminating request, since the same name would be 429 either way and
     *    would prove nothing).
     *
     *    Two things in that neighbourhood are deliberately NOT claimed as pinned,
     *    because nothing observable depends on them and a green suite cannot see
     *    them. Both mutations below were measured 2026-10-04, on this text as it
     *    stood; each says which classes stayed green.
     *
     *    - *Reading the header only after the environment gate* — an
     *      implementation nicety, not a security property. Moving
     *      `$request->header(…)` ABOVE the allow-list leaves the class green,
     *      because a header read has no effect on the key. The **gate** is the
     *      security property and it is pinned: by
     *      `test_the_actor_header_is_ignored_in_production` (key form),
     *      `test_the_actor_header_is_ignored_in_every_environment_outside_local_and_testing`
     *      (the allow-list itself) and
     *      `test_the_actor_header_does_not_split_the_bucket_in_production_over_http`
     *      (the claim end to end).
     *    - *The brute-force NUMBERS* — `login` 15/min and `register` 10/min per
     *      ip are the production values chosen at the two env-dependent floors in
     *      `boot()`, a deliberate decision, not a test-asserted fact: no test
     *      anywhere reads `maxAttempts` for a production environment.
     *      `AuthThrottleTest` pins only the `local`/`testing` side (40/30), and
     *      mutating `15 → 5` leaves `AuthThrottleTest` AND
     *      `RateLimitTestActorKeyTest` green (measured). The budgets are frozen
     *      into the limiters when `boot()` runs, which is also why the production
     *      tests above assert the KEY and never `maxAttempts`.
     * 2. **The header cannot be used outside `local`/`testing`.** Not
     *    `production`, not `staging`, not any other name — the check is a
     *    positive allow-list, so an environment nobody thought of behaves like
     *    production rather than like a test.
     * 3. **The actor value is a bucket NAME, never a credential.** It is
     *    length-capped and restricted to `[A-Za-z0-9._-]`; anything else is
     *    discarded and the request falls back to the plain per-ip bucket. That
     *    direction is deliberate: a rejected header makes the caller STRICTER
     *    (it lands in the shared bucket), never more privileged.
     * 4. **Known cost, stated rather than hidden:** in `local`/`testing` a
     *    client that rotates the header gets fresh buckets, so the per-ip
     *    limit is no longer a limit for that client in those two environments.
     *    That is the point of the carve-out and it is accepted only because
     *    those environments are not internet-exposed and their budgets are
     *    already raised (40/30/300 vs 15/10/60). Anyone who wants the real
     *    brute-force behaviour locally simply does not send the header —
     *    which is the default for curl, for a serial run and for the ui-review
     *    screenshot suite.
     *
     * ## Deliberately NOT applied to the user-keyed limiters
     *
     * Six buckets are keyed on the AUTHENTICATED identity, and the list is
     * deliberately complete rather than illustrative — an enumeration that names
     * four of six is the shape that rots quietly.
     *
     * 1. `apply` — `boot()` below.
     * 2. `media` — `boot()` below.
     * 3. `admin` — `boot()` below.
     * 4. `resend` — `boot()` below.
     * 5. `auth-logout` — `POST /api/auth/logout`, inline in `routes/api.php:106`
     *    (`throttle:60,1,auth-logout`), keyed per authenticated user by Laravel's
     *    `ThrottleRequests::resolveRequestSignature()`.
     * 6. `auth-me` — `GET /api/auth/me`, `routes/api.php:107`, same mechanism,
     *    its own limiter PREFIX (both inline routes carry the third parameter
     *    precisely because the user id is the whole signature).
     *
     * Items 5 and 6 are the same class as `admin` and are excluded for the same
     * reason; they are listed separately because they are not named limiters and
     * a reader grepping `RateLimiter::for(` would otherwise not find them. They
     * are also the ONLY two inline `throttle:N,M,prefix` routes in that file —
     * every other throttle on `routes/api.php` is a named limiter from `boot()` —
     * so the six above are the complete set, not a sample.
     *
     * Adding the actor to any of the six would not split a per-ip quota (there is
     * none) — it would split a per-USER budget, which is a security property:
     * one account flooding the admin write surface must stay visible as one
     * counter. The E2E suite shares a single admin account, so `admin:{id}` is
     * indeed shared across workers; that is a separate, deliberate budget
     * question, not this one.
     */
    public static function throttleKeyFor(Request $request, string $bucket): string
    {
        $actor = self::testActorFor($request);

        return $bucket.':'.$request->ip().($actor === null ? '' : '@'.$actor);
    }

    /**
     * The test actor this request speaks for, or `null` for the plain per-ip
     * bucket. See {@see self::throttleKeyFor()} — the three ways this returns
     * `null` are: wrong environment, header absent, header not acceptable.
     */
    private static function testActorFor(Request $request): ?string
    {
        // Positive allow-list of the two non-production environments. Anything
        // else — including an environment this code never heard of — is
        // production as far as the throttle key is concerned.
        if (! app()->environment('local', 'testing')) {
            return null;
        }

        $actor = $request->header(self::TEST_ACTOR_HEADER);

        if (! is_string($actor) || preg_match(self::TEST_ACTOR_PATTERN, $actor) !== 1) {
            return null;
        }

        return $actor;
    }

    /**
     * R-D8 / WP-1-e: refuse to boot a production application with an empty or
     * placeholder `APP_KEY`.
     *
     * `deployment/docker-compose.yml` shipped `APP_KEY: ${APP_KEY:-base64:AAAA…}`,
     * a *working* key (32 zero bytes) that is public knowledge. Every
     * `Crypt` payload — encrypted media paths, `encrypted:json` columns — and
     * every HMAC-signed token (QR codes) would be forgeable, silently and
     * without a single error message. The compose side removes the default
     * (`${APP_KEY:?…}`); this is the application-side backstop that also
     * covers every other deployment path (bare `APP_ENV=production`, a
     * forgotten `php artisan key:generate`).
     *
     * Mirrors the `DatabaseSeeder` admin-password policy (skip/warn in
     * non-production, hard refusal in production).
     *
     * @throws RuntimeException in `production` with an unusable key.
     */
    public static function assertProductionAppKeyIsStrong(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $key = config('app.key');
        $key = is_string($key) ? trim($key) : '';

        if ($key !== '' && ! self::isPlaceholderKey($key)) {
            return;
        }

        Log::critical('Refusing to boot: APP_KEY is empty or a known placeholder in production. Run `php artisan key:generate` and set APP_KEY in the deployment environment.', [
            'app_env' => app()->environment(),
            'key_configured' => $key !== '',
        ]);

        throw new RuntimeException(
            'Refusing to boot in production: APP_KEY is empty or a known placeholder key. Generate one with `php artisan key:generate` and provide it via the APP_KEY environment variable.',
        );
    }

    /**
     * Whether the key is one of the well-known throwaway values: the 32-byte
     * all-zero key (the compose default), its base64 form with or without the
     * `base64:` prefix, the raw 32 zero bytes, or the 32-byte all-`A` key that
     * is easy to produce by accident. Both base64 spellings are checked
     * because an operator pasting `base64_encode(str_repeat("\0", 32))` without
     * its prefix is a realistic mistake.
     */
    private static function isPlaceholderKey(string $key): bool
    {
        $zeros = str_repeat("\0", 32);
        $repeated = str_repeat('A', 32);

        $candidates = [$key, (string) base64_decode($key, true)];

        if (str_starts_with($key, 'base64:')) {
            $raw = substr($key, 7);
            $candidates[] = $raw;
            $candidates[] = (string) base64_decode($raw, true);
        }

        foreach ($candidates as $candidate) {
            if (hash_equals($zeros, $candidate) || hash_equals($repeated, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
