<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // reads (60/min per ip).
        RateLimiter::for('activate', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('activate:'.$request->ip()));
        RateLimiter::for('public', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by('public:'.$request->ip()));
    }
}
