<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Position 49: the ip-keyed rate-limit buckets gain a TEST ACTOR, so parallel
 * test workers stop sharing one bucket.
 *
 * The measured problem: a suite has ONE client ip regardless of its worker
 * count, so `login`/`register`/`activate`/`public`/`verify` are one shared
 * bucket at every worker count — a bigger budget only moves the wall. The fix
 * is a throttle key per test actor (skill `playwright-parallel`, „Lock vs.
 * rate limiter“), and the actor is only ever read in `local`/`testing`.
 *
 * What this class pins, and why each part is a separate test:
 *
 *  * **the key form per environment** — `production` must produce the exact
 *    string the old inline `'{bucket}:'.$request->ip()` produced, byte for
 *    byte, and `local`/`testing` must produce the actor form. A single
 *    "keys are distinct" assertion could not tell a correct split from a
 *    renamed key.
 *  * **parallel actors do NOT share a bucket** — this is the actual claim of
 *    the change, and it is measured over HTTP, not over the key string.
 *  * **the same actor DOES share its bucket** — without this, "every request
 *    gets its own key" would pass the test above while throttling nothing.
 *  * **a malformed actor header falls back to the shared per-ip bucket** —
 *    the fail-stricter direction. An actor value is a bucket NAME; a value the
 *    backend will not accept must not become a way around the budget.
 *
 * @see AppServiceProvider::throttleKeyFor() for the contract this tests.
 */
class RateLimitTestActorKeyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The `login` budget in `testing` (AppServiceProvider: `local`/`testing`
     * floor). Read from the registered limiter rather than hardcoded, so the
     * exhaustion tests stay correct if the floor moves.
     */
    private function loginBudget(): int
    {
        return RateLimiter::limiter('login')(Request::create('/api/auth/login', 'POST'))->maxAttempts;
    }

    private function request(string $uri = '/api/auth/login', string $ip = '10.0.0.9'): Request
    {
        $request = Request::create($uri, 'POST');
        $request->server->set('REMOTE_ADDR', $ip);

        return $request;
    }

    private function withActor(Request $request, string $actor): Request
    {
        $request->headers->set(AppServiceProvider::TEST_ACTOR_HEADER, $actor);

        return $request;
    }

    /**
     * Runs `$callback` as if the app booted in `$environment`.
     *
     * `$this->app->instance('env', …)` is the supported seam:
     * `Application::environment()` reads `$this['env']` (framework
     * `Application.php:756-765`), so the very expression the limiters call
     * resolves to the new value. NOTE the scope: this changes what the key
     * helper computes — the *budgets* are read once when `boot()` registered
     * the limiters and stay at their `testing` values, which is why the
     * environment tests below assert the key and not `maxAttempts`.
     *
     * Two production-only side effects of the swap are neutralised here and
     * restored in the `finally`: `TrustHosts` stops trusting everything in a
     * non-`local` environment (the suite's host `accreditation.test` is only
     * allow-listed by the non-production wildcard, so requests would answer
     * 400 before reaching any controller), and it does so by writing a STATIC
     * pattern list, which would otherwise leak into every later test in this
     * process. Both are set, not assumed — see
     * `Illuminate\Http\Middleware\TrustHosts::shouldSpecifyTrustedHosts()`.
     */
    private function inEnvironment(string $environment, callable $callback): mixed
    {
        $original = $this->app->instance('env', $environment);

        try {
            if ($environment !== 'local') {
                config(['security.trusted_hosts' => 'accreditation\.test']);
            }

            return $callback();
        } finally {
            Request::setTrustedHosts([]);
            $this->app->instance('env', $original);
        }
    }

    /* ---------------------------------------------------------------------
     | Key form per environment.
     | ------------------------------------------------------------------- */

    public function test_in_testing_a_request_without_the_actor_header_keeps_the_plain_ip_key(): void
    {
        // No header — the default for curl, a serial run and the screenshot
        // suite. This must be the unchanged `login:{ip}` string, because those
        // callers must not silently lose their throttle.
        $this->assertSame('login:10.0.0.9', RateLimiter::limiter('login')($this->request())->key);
    }

    public function test_in_testing_the_actor_header_is_appended_to_the_ip_key(): void
    {
        $key = RateLimiter::limiter('login')($this->withActor($this->request(), 'w3-p1234'))->key;

        // The ip stays in the key: a bucket remains attributable to an address,
        // and two actors on different addresses still do not share a bucket.
        $this->assertSame('login:10.0.0.9@w3-p1234', $key);
    }

    public function test_the_actor_header_is_ignored_in_production(): void
    {
        $this->inEnvironment('production', function (): void {
            $withHeader = RateLimiter::limiter('login')($this->withActor($this->request(), 'w3-p1234'))->key;
            $withoutHeader = RateLimiter::limiter('login')($this->request())->key;

            // Byte-identical to the pre-Position-49 inline `by('login:'.$ip)`,
            // and identical to the no-header form: the header does not REACH the
            // key, so no actor suffix appears. (Whether the header accessor is
            // called at all is an implementation detail this test deliberately
            // does not claim — MEASURED 2026-10-04, reading the header before the
            // environment gate leaves this class green. The gate is what is
            // pinned; see the security contract in `AppServiceProvider`.)
            $this->assertSame('login:10.0.0.9', $withHeader);
            $this->assertSame($withoutHeader, $withHeader);
        });
    }

    public function test_an_actor_header_ending_in_a_newline_is_rejected(): void
    {
        // MEASURED 2026-10-04, and the reason the provider's pattern ends in PCRE
        // `\z` instead of `$`: `$` also matches IMMEDIATELY BEFORE a trailing
        // newline, so `/^[A-Za-z0-9._-]{1,32}$/` returns 1 for "w1\n" — the whole
        // string, newline included, then lands in the cache key
        // (`login:10.0.0.9@w1\n`). A bucket name is all this value ever is, so
        // that is hygiene rather than a hole; the real damage was the
        // cross-language disagreement: JavaScript's `$` (no `/m`) already means
        // `\z`, so `frontend/tests/e2e/throttle-actor.test.ts`, which READS this
        // pattern out of the provider source, was evaluating a stricter rule than
        // the backend applied — and nothing noticed, because both sides were
        // "tested".
        $this->assertSame(
            'login:10.0.0.9',
            RateLimiter::limiter('login')($this->withActor($this->request(), "w1\n"))->key,
            'an actor with a trailing newline must fall back to the shared per-ip bucket, not enter the key',
        );

        // The plain form still works, so the assertion above is about the
        // trailing newline and not about a pattern that rejects everything.
        $this->assertSame(
            'login:10.0.0.9@w1',
            RateLimiter::limiter('login')($this->withActor($this->request(), 'w1'))->key,
        );
    }

    public function test_the_actor_header_is_ignored_in_every_environment_outside_local_and_testing(): void
    {
        // A positive allow-list, so an environment nobody enumerated behaves
        // like production rather than like a test.
        foreach (['staging', 'prod', 'ci', ''] as $environment) {
            $this->inEnvironment($environment, function () use ($environment): void {
                $key = RateLimiter::limiter('login')($this->withActor($this->request(), 'w3-p1234'))->key;

                $this->assertSame('login:10.0.0.9', $key, "environment [{$environment}] must not honour the test actor");
            });
        }
    }

    public function test_every_ip_keyed_limiter_honours_the_actor(): void
    {
        // login, register, activate, public and verify are the ip-keyed ones;
        // all five must go through the same helper, or the split protects only
        // the surface that happened to be migrated.
        $buckets = [
            'login' => '/api/auth/login',
            'register' => '/api/auth/register',
            'activate' => '/api/auth/activate/abc123',
            'public' => '/api/portal/overview',
            'verify' => '/api/verify/abc123',
        ];

        foreach ($buckets as $bucket => $uri) {
            $request = $this->request($uri);
            $request->server->set('REMOTE_ADDR', '10.0.0.10');

            $this->assertSame(
                $bucket.':10.0.0.10@w3-p1234',
                RateLimiter::limiter($bucket)($this->withActor($request, 'w3-p1234'))->key,
                "limiter [{$bucket}] must carry the test actor",
            );
        }
    }

    /* ---------------------------------------------------------------------
     | The actual claim: parallel actors do not share a bucket. Measured over
     | HTTP, because a key-string assertion cannot tell a working split from a
     | well-formed key nothing uses.
     | ------------------------------------------------------------------- */

    public function test_parallel_test_actors_do_not_share_one_bucket(): void
    {
        $budget = $this->loginBudget();

        // Worker A exhausts its own bucket.
        $this->withHeader(AppServiceProvider::TEST_ACTOR_HEADER, 'w0-p111');
        for ($i = 0; $i < $budget; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }
        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);

        // Worker B, SAME ip, must be unaffected: 401 (wrong credentials), not
        // 429. Before this change it was a 429 — that cluster of identical
        // failures is exactly what position 49 is about.
        $this->withHeader(AppServiceProvider::TEST_ACTOR_HEADER, 'w1-p222');
        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_the_same_test_actor_still_shares_one_bucket(): void
    {
        $budget = $this->loginBudget();

        // Same actor throughout — the throttle must actually throttle. Without
        // this test, "give every request its own key" would satisfy the split
        // above while removing the budget entirely.
        $this->withHeader(AppServiceProvider::TEST_ACTOR_HEADER, 'w0-p111');
        for ($i = 0; $i < $budget; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_a_client_without_an_actor_header_keeps_the_shared_ip_bucket(): void
    {
        $budget = $this->loginBudget();

        // No header at all (plain curl / serial run): unchanged behaviour.
        for ($i = 0; $i < $budget; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_a_malformed_actor_header_falls_back_to_the_shared_ip_bucket(): void
    {
        // Values the backend refuses to use as a bucket name. Each must land in
        // the SAME, stricter, shared per-ip bucket — never in a private one.
        $malformed = [
            '',
            'has space',
            'slash/and:colon',
            '../etc/passwd',
            str_repeat('a', 33),
        ];

        $budget = $this->loginBudget();

        foreach ($malformed as $value) {
            $this->assertSame(
                'login:10.0.0.9',
                RateLimiter::limiter('login')($this->withActor($this->request(), $value))->key,
                sprintf('actor [%s] must not produce a private bucket', var_export($value, true)),
            );
        }

        // And over HTTP: exhaust the SHARED per-ip bucket with a malformed
        // actor, then a request with NO header at all — the other caller of
        // that bucket — must already be throttled. (A well-formed actor would
        // not be: it has its own bucket, which is the whole point of the
        // split, so asserting that here would test the wrong thing.)
        $this->withHeader(AppServiceProvider::TEST_ACTOR_HEADER, 'has space');
        for ($i = 0; $i < $budget; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }

        $this->flushHeaders();
        $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_the_actor_header_does_not_split_the_bucket_in_production_over_http(): void
    {
        // Production form, end to end: the actor does not split anything, so a
        // client cannot escape its own budget by naming itself. Scope note: the
        // env swap changes the key helper only — the budget was fixed at boot
        // and stays at the `testing` floor, which is why the count below is
        // read from the registered limiter.
        $budget = $this->loginBudget();

        $this->inEnvironment('production', function () use ($budget): void {
            $this->withHeader(AppServiceProvider::TEST_ACTOR_HEADER, 'w3-p1234');
            for ($i = 0; $i < $budget; $i++) {
                $this->postJson('/api/auth/login', [
                    'email' => 'unknown@example.com',
                    'password' => 'wrong-password',
                ])->assertStatus(401);
            }

            // A DIFFERENT actor name, same ip → still 429. This is the claim the
            // whole carve-out is built on: outside `local`/`testing` naming
            // yourself buys nothing, so a client cannot walk past its own
            // brute-force budget by varying a header. (SAME actor would also be
            // 429 in either world and would therefore prove nothing here — the
            // discriminating request is the second NAME.)
            $this->withHeader(AppServiceProvider::TEST_ACTOR_HEADER, 'w4-p5678');
            $this->postJson('/api/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(429);
        });
    }
}
