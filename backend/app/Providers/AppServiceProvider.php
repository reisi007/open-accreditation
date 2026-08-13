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
        // Login throttles per authenticated user id, falling back to per-ip
        // for unauthenticated (failed) attempts; register is per-ip.
        // P2b-F9: login budget raised 10 → 15/min — the E2E @smoke suite needs
        // ~11–13 logins per minute (session-switching tests). 15/min keeps the
        // brute-force protection intact. Register stays at 10/min.
        RateLimiter::for('login', static fn (Request $request): Limit => Limit::perMinute(15)
            ->by('login:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('register', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('register:'.$request->ip()));

        // P3b-F1: applying for accreditations throttles per authenticated user
        // (a scripted flood of applications across many accreditations is the
        // threat — quota is not enforced at apply time), falling back to per-ip
        // for unauthenticated requests.
        RateLimiter::for('apply', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('apply:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
