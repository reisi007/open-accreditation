<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The per-process token must stay correct under `paratest`.
 *
 * `Storage::fake()` already isolates the workers of a `--parallel` run from each
 * other: paratest exports `TEST_TOKEN` per worker, so their roots differ even
 * before the per-process token exists. Two things could still break that, and
 * neither is visible in a plain sequential run — which is the run almost every
 * developer and agent does, and therefore the only one that would have caught
 * the original race:
 *
 *  1. Dropping the paratest token from the composition. `TEST_TOKEN` is then no
 *     longer part of the root, so paratest's own worker numbering stops
 *     distinguishing anything and the PID alone has to carry it. The run stays
 *     green — but roots become unreadable in a failure report (`media_test_45123`
 *     instead of `media_test_3-45123`), and the isolation would rest on a single
 *     mechanism where the framework already supplies another.
 *  2. Treating a `TEST_TOKEN` that is set but blank as a worker identity.
 *     paratest never does that, but a stray `TEST_TOKEN=0 php artisan test`
 *     would otherwise produce a `media_test_0-45123` root.
 *
 * These run in a plain sequential suite — the run almost every developer and
 * agent does, and the only one that ever exposed the original race — so they
 * exercise the composition directly instead of waiting for a parallel run to
 * reveal a problem. Both mutations were applied and re-run:
 * `test_a_paratest_worker_token_is_kept_as_a_prefix` and
 * `test_a_blank_paratest_token_is_ignored` (data set #1) turn red respectively.
 *
 * The paratest run itself was verified separately: `php artisan test --parallel
 * --processes=2` is green over the full suite, and this class is green under a
 * plain run, under `--parallel`, and with `TEST_TOKEN` exported as `7`, `0` and
 * empty — so the composition does not depend on how the suite was invoked.
 */
class FakeDiskParallelTokenTest extends TestCase
{
    /**
     * The composition is pinned by calling the resolver with a stubbed
     * `TEST_TOKEN`, which is why the resolver is reachable as its own method:
     * `Storage::fake()` reads the token through the container, and by the time a
     * test body runs, that value is already fixed.
     */
    public function test_a_paratest_worker_token_is_kept_as_a_prefix(): void
    {
        $this->assertSame(
            '3-'.getmypid(),
            $this->resolveTokenFor('3'),
            'the paratest worker number must stay in the root, so a parallel run keeps its readable, '
            .'per-worker paths and the workers stay distinguishable from other runs',
        );
    }

    public function test_a_plain_run_token_is_the_pid_alone(): void
    {
        // A plain `php artisan test` has no TEST_TOKEN — that absence is the
        // whole reason two such runs used to collide.
        $this->assertSame(
            (string) getmypid(),
            $this->resolveTokenFor(null),
            'without a paratest token the process token must be the bare pid',
        );
    }

    /**
     * An empty or zero `TEST_TOKEN` is not a worker identity.
     *
     * `ParallelTesting::token()` returns `$_SERVER['TEST_TOKEN'] ?? false`, and
     * `Storage::fake()` only appends the suffix `if ($token = …)` — so a `'0'`
     * is falsy for the framework too. Matching that exactly is what keeps a
     * hand-set `TEST_TOKEN=0` from producing a `media_test_-45123` root.
     */
    #[DataProvider('blankParatestTokenProvider')]
    public function test_a_blank_paratest_token_is_ignored(string $paratestToken): void
    {
        $this->assertSame(
            (string) getmypid(),
            $this->resolveTokenFor($paratestToken),
            "[{$paratestToken}] is not a worker identity and must not end up in the root path",
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function blankParatestTokenProvider(): array
    {
        return [[''], ['0']];
    }

    /**
     * Every token shape must produce a path-safe, non-empty suffix.
     *
     * An empty token would be falsy in `Storage::fake()`, which skips the suffix
     * entirely and lands every process back on the shared root — the original
     * bug, reached silently instead of loudly.
     *
     * @return list<array{0: string|null}>
     */
    public static function paratestTokenProvider(): array
    {
        return [[null], [''], ['0'], ['3'], ['12'], ['worker-a']];
    }

    #[DataProvider('paratestTokenProvider')]
    public function test_the_token_is_always_usable_as_a_path_suffix(?string $paratestToken): void
    {
        $token = $this->resolveTokenFor($paratestToken);

        $this->assertNotSame('', $token, 'an empty token makes Storage::fake() skip the suffix and share the root');

        $this->assertDoesNotMatchRegularExpression(
            '#[/\\\\]#',
            $token,
            'the token becomes a path segment, so a separator in it would create a nested directory',
        );

        // Whatever `TEST_TOKEN` the ambient environment happens to carry, the
        // token this process faked its disks with must be exactly what the
        // resolver produces for that same environment. Comparing against
        // `resolveTokenFor(null)` here would be wrong on its own: a developer
        // running with `TEST_TOKEN=3` exported, or a CI job that sets it, is a
        // legitimate run whose live token is `3-<pid>`.
        $ambient = $_SERVER['TEST_TOKEN'] ?? null;

        $this->assertSame(
            $this->resolveTokenFor(is_string($ambient) ? $ambient : null),
            TestCase::fakeDiskToken(),
            'the token this process actually faked its disks with must come from that same resolver, '
            .'for the TEST_TOKEN this process actually ran with ('.var_export($ambient, true).')',
        );
    }

    /**
     * Resolve the token as if `TEST_TOKEN` were `$paratestToken`, using the
     * production resolver.
     *
     * Mirrors `Tests\TestCase::resolveFakeDiskToken()`'s single source of truth
     * rather than restating it: the value under test is the composition, and
     * duplicating the expression here would let the two drift apart — which is
     * exactly the class of bug the paratest test exists to prevent.
     */
    private function resolveTokenFor(?string $paratestToken): string
    {
        $previous = $_SERVER['TEST_TOKEN'] ?? null;

        try {
            if ($paratestToken === null) {
                unset($_SERVER['TEST_TOKEN']);
            } else {
                $_SERVER['TEST_TOKEN'] = $paratestToken;
            }

            $method = new ReflectionMethod(TestCase::class, 'resolveFakeDiskToken');

            return (string) $method->invoke(null);
        } finally {
            if ($previous === null) {
                unset($_SERVER['TEST_TOKEN']);
            } else {
                $_SERVER['TEST_TOKEN'] = $previous;
            }
        }
    }
}
