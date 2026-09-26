<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The fake-disk root must be unique per OS process.
 *
 * `Tests\TestCase` (WP-11) makes every test *start* from an empty fake disk and
 * leaves nothing behind afterwards. That fixed history dependence, i.e. a run
 * depending on an EARLIER run in the same checkout — but not the cross-process
 * race, and the difference is the root path:
 *
 *   - `Storage::fake()` appends `ParallelTesting::token()` to
 *     `storage/framework/testing/disks/<disk>`, and that token is
 *     `$_SERVER['TEST_TOKEN']`, which only paratest sets. So paratest workers of
 *     ONE run were already isolated (`…/media_test_1`, `…/media_test_2`), while
 *     two plain `php artisan test` processes both got the bare shared
 *     `…/media` — and `fake()` empties that directory on every single call, so
 *     each run deleted the other's files mid-test. Measured on this suite
 *     before the fix: **383** spurious failures in one run, **5** in the other.
 *   - `Tests\TestCase::isolateFakeDisksInThisProcess()` resolves that token to a
 *     per-process value, which is what makes concurrent runs independent.
 *
 * These tests pin that, and every one of them was mutation-checked. Four
 * mutations, each re-run against this class:
 *
 *  A. `isolateFakeDisksInThisProcess()` dropped from `Tests\TestCase::setUp()`
 *     — the original defect.
 *       → `test_the_faked_root_is_not_the_shared_one` red (root is the bare
 *         shared path), and
 *       → `test_a_concurrent_suite_cannot_see_or_destroy_our_files` red on both
 *         sides: the child probe's own root assertion fails, and the parent
 *         finds `parent-only.txt` deleted from its root.
 *       → `test_the_root_does_not_move_within_a_process` stays GREEN, correctly:
 *         a shared root is still a stable one. It pins the other half of the
 *         contract, which the concurrency test cannot see.
 *  B. token made a constant (`'fixed'`) — unique root, no per-process part.
 *       → the concurrent test red: the child resolves OUR root and its
 *         `fake()` wipes the file the parent just wrote.
 *  C. token recomputed per call instead of cached per process.
 *       → `test_the_faked_root_is_not_the_shared_one` red (the re-fake in the
 *         stability test lands on a new root), and the concurrent test red.
 *  D. the end-of-process sweep widened to delete every root in the tree.
 *       → `test_the_cleanup_only_touches_roots_of_its_own_token` red (a foreign
 *         token's root was destroyed — a live suite would lose its files), and
 *         the concurrent test red with it.
 *
 * Two further mutations, applied to the legacy prune, both turning
 * `test_roots_from_the_untokenized_era_are_pruned` red: replacing the exact-name
 * comparison with a `str_contains` match over the tree's directories (the
 * realistic way to get this wrong, and it deletes live `<disk>_test_<token>`
 * roots), and removing the whole tree.
 */
class FakeDiskProcessIsolationTest extends TestCase
{
    private const DISK = 'media';

    /**
     * The probe: a real test class, deliberately outside every testsuite, run as
     * its own `php artisan test` process. See
     * `Tests\Support\FakeDiskSuiteProbeTest`.
     */
    private const PROBE = __DIR__.'/../Support/FakeDiskSuiteProbeTest.php';

    /* ---------------------------------------------------------------------
     | The token
     | ------------------------------------------------------------------- */

    /**
     * The wiring itself. `$this->fakeDiskRoots()` is filled in `setUp()`, so
     * this observes exactly what every `Storage::fake()` call of every test
     * class in the suite gets.
     *
     * Catches: dropping `isolateFakeDisksInThisProcess()` from `setUp()` — the
     * root silently falls back to the shared `…/disks/media` and this is the
     * test that says so.
     */
    public function test_the_faked_root_is_not_the_shared_one(): void
    {
        $root = $this->fakeDiskRoots()[self::DISK];
        $shared = storage_path('framework/testing/disks/'.self::DISK);

        $this->assertNotSame(
            $shared,
            $root,
            'the fake root is the bare shared path, so every process in this checkout writes to it',
        );

        $this->assertSame(
            $shared.'_test_'.TestCase::fakeDiskToken(),
            $root,
            'the fake root must be the shared path plus this process\' own token, and nothing else',
        );
    }

    /**
     * The token is not empty. An empty token is silently falsy in
     * `if ($token = ParallelTesting::token())`, so the suffix would be skipped
     * and the root would quietly become the shared one again — the exact failure
     * above, reached through `getmypid()` misbehaving rather than through a
     * missing resolver.
     */
    public function test_the_process_token_is_not_empty(): void
    {
        $this->assertNotSame('', TestCase::fakeDiskToken());
    }

    /**
     * The root is STABLE for the whole process.
     *
     * This is the property that makes the isolation usable rather than just
     * unique: `setUp()` captures the root, the test body writes through the
     * disk, and `tearDown()` empties the very same directory. A token computed
     * per call (a fresh `uniqid()`, say) would leave the suite writing into a
     * root nobody cleans — and `TestDiskIsolationTest` plus every media test
     * with a write-then-read assertion would fail.
     *
     * Catches: mutation C in the class docblock — the token recomputed per call.
     */
    public function test_the_root_does_not_move_within_a_process(): void
    {
        $token = TestCase::fakeDiskToken();

        $this->assertSame($token, TestCase::fakeDiskToken(), 'the token must be resolved once per process');

        $root = $this->fakeDiskRoots()[self::DISK];

        // Re-faking the same disk again — what the ~25 test classes that call
        // `Storage::fake()` themselves do — must land on the same root.
        //
        // Proved with a marker, because comparing paths after the fact is blind
        // here: `Storage::disk()` reports whichever root the last `fake()`
        // installed, so it agrees with itself either way. What distinguishes the
        // two cases is WHERE the re-fake cleaned: `fake()` empties its own root,
        // so on a stable root the marker is gone afterwards, while a root that
        // moved leaves the marker sitting in the directory the test is still
        // using — a file nothing will ever read back and nothing will clean.
        Storage::disk(self::DISK)->put('stability-probe.txt', 'still here');

        Storage::fake(self::DISK);

        $this->assertSame(
            $root,
            rtrim(Storage::disk(self::DISK)->path(''), DIRECTORY_SEPARATOR),
            'a second Storage::fake() in the same process must resolve the same root',
        );

        $this->assertNotContains(
            'stability-probe.txt',
            $this->listTree($root),
            'the re-fake cleaned some other directory and left this one untouched — the root moves '
            .'between calls, so writes land where nothing reads them back',
        );
    }

    /* ---------------------------------------------------------------------
     | Two processes
     | ------------------------------------------------------------------- */

    /**
     * The invariant, observed from a genuine second `php artisan test`
     * process: two concurrent suites in one checkout cannot see — or destroy —
     * each other's files.
     *
     * The child is a real suite run of `Tests\Support\FakeDiskSuiteProbeTest`,
     * so it goes through the very same `Tests\TestCase::setUp()` this process
     * used. A hand-rolled script that installs the isolation itself would be
     * weaker: it would stay green even if `setUp()` stopped doing so, which is
     * exactly the regression this has to catch (mutation-checked — see the class
     * docblock).
     *
     * Catches mutations A, B, C and D in the class docblock — a lost `setUp()`
     * wiring, a token that is not unique per process, a token that is not stable
     * within one, and a cleanup sweep wide enough to reach another process's
     * root.
     */
    public function test_a_concurrent_suite_cannot_see_or_destroy_our_files(): void
    {
        $ourRoot = $this->fakeDiskRoots()[self::DISK];

        Storage::disk(self::DISK)->put('parent-only.txt', 'ours');

        $this->assertContains(
            'parent-only.txt',
            $this->listTree($ourRoot),
            'the fixture is broken: nothing was written',
        );

        $child = $this->runConcurrentSuite();

        $this->assertNotSame(
            $ourRoot,
            $child['root'],
            'a second process resolved the SAME fake root — concurrent runs will delete each other\'s files',
        );

        $this->assertNotSame(
            getmypid(),
            $child['pid'],
            'the probe did not actually run in a separate process',
        );

        $this->assertNotContains(
            'parent-only.txt',
            $child['tree_before'],
            'the child process sees a file this process wrote — the roots are shared',
        );

        $this->assertContains(
            'child-only.txt',
            $child['tree_after'],
            'the probe is broken: its own write did not land in its own root',
        );

        // And back the other way: the child wrote and wrote nothing of ours away.
        $this->assertContains(
            'parent-only.txt',
            $this->listTree($ourRoot),
            'the child process deleted a file this process had written — that is the race this guards',
        );

        $this->assertNotContains(
            'child-only.txt',
            $this->listTree($ourRoot),
            'the child process\'s file showed up in our root — the roots are shared',
        );
    }

    /* ---------------------------------------------------------------------
     | Cleanup of the process' own roots
     | ------------------------------------------------------------------- */

    /**
     * The end-of-process sweep removes this process' roots and nothing else.
     *
     * This is what keeps `storage/framework/testing/` down to its tracked
     * `.gitignore`: the per-process suffix means a run would otherwise leave
     * four `…_test_<pid>` directories behind. The counter-example in the
     * fixture is the important half — a root belonging to a DIFFERENT token is a
     * root belonging to a process that may be running right now, and deleting it
     * would reintroduce the very race.
     *
     * A throwaway tree, so the real one is untouched.
     */
    public function test_the_cleanup_only_touches_roots_of_its_own_token(): void
    {
        $tree = $this->makeTempTree();

        try {
            $ours = $tree.'/media_test_'.TestCase::fakeDiskToken();
            $someoneElses = $tree.'/media_test_999999';
            $shared = $tree.'/media';

            foreach ([$ours, $someoneElses, $shared] as $directory) {
                (new Filesystem)->ensureDirectoryExists($directory.'/nested');
                file_put_contents($directory.'/nested/file.txt', 'x');
            }

            $removed = TestCase::purgeFakeDiskRootsOwnedBy($tree, TestCase::fakeDiskToken());

            $this->assertSame(
                [basename($ours)],
                $removed,
                'the sweep must remove exactly the one root carrying this process\' token',
            );

            $this->assertDirectoryDoesNotExist($ours);

            $this->assertFileExists(
                $someoneElses.'/nested/file.txt',
                'the sweep deleted a root of another token — a concurrently running suite would lose its files',
            );

            $this->assertFileExists(
                $shared.'/nested/file.txt',
                'the sweep deleted an untokened root, which is not this process\' to remove',
            );
        } finally {
            (new Filesystem)->deleteDirectory($tree);
        }
    }

    /**
    /**
     * Roots left behind by the pre-tokenization layout get cleared, so
     * `storage/framework/testing/` really does return to just its tracked
     * `.gitignore`.
     *
     * Without the per-process suffix, `Storage::fake()` rooted at
     * `…/disks/media` and left it there forever, so a checkout can carry files
     * from runs of the old code. Nothing reads them any more, but they would
     * make the tree never look clean again.
     */
    public function test_roots_from_the_untokenized_era_are_pruned(): void
    {
        $tree = $this->makeTempTree();

        try {
            $legacy = [];
            $tokenized = [];

            foreach (self::FAKE_DISKS as $disk) {
                $legacy[$disk] = $tree.'/'.$disk;
                $tokenized[$disk] = $tree.'/'.$disk.'_test_'.TestCase::fakeDiskToken();

                foreach ([$legacy[$disk], $tokenized[$disk]] as $directory) {
                    (new Filesystem)->ensureDirectoryExists($directory.'/nested');
                    file_put_contents($directory.'/nested/file.txt', 'x');
                }
            }

            $this->assertFileExists($legacy['media'].'/nested/file.txt', 'the fixture is broken');

            // The precise set, not just "the four are gone": a sweep that also
            // removed a `_test_<token>` root would satisfy that while destroying
            // a live suite's files, and the check below is what catches it.
            $this->assertSame(
                self::FAKE_DISKS,
                TestCase::pruneLegacySharedRootsIn($tree),
                'the prune must remove exactly the untokenized roots of the faked disks, and nothing else',
            );

            foreach (self::FAKE_DISKS as $disk) {
                $this->assertDirectoryDoesNotExist(
                    $legacy[$disk],
                    "the untokenized root for [{$disk}] survived, so storage/framework/testing/ never goes clean again",
                );

                $this->assertFileExists(
                    $tokenized[$disk].'/nested/file.txt',
                    "the sweep deleted [{$disk}]'s OWN root — a live suite would lose its files",
                );
            }
        } finally {
            (new Filesystem)->deleteDirectory($tree);
        }
    }

    public function test_the_cleanup_is_a_no_op_without_a_token(): void
    {
        $tree = $this->makeTempTree();

        try {
            (new Filesystem)->ensureDirectoryExists($tree.'/media_test_1234');

            $this->assertSame([], TestCase::purgeFakeDiskRootsOwnedBy($tree, ''));
            $this->assertSame([], TestCase::purgeFakeDiskRootsOwnedBy($tree.'/does-not-exist', '1234'));

            $this->assertDirectoryExists($tree.'/media_test_1234');
        } finally {
            (new Filesystem)->deleteDirectory($tree);
        }
    }

    /**
     * A missing disk root is a no-op, not an error.
     *
     * The end-of-process sweep runs from a shutdown function, where a
     * throwaway root may already be gone (a test that deleted it, or a process
     * whose first `fake()` was never reached because it died during bootstrap).
     * `phpunit.xml` sets `failOnWarning="true"`, so an unguarded filesystem
     * complaint here would surface as a red suite for a process that did
     * nothing wrong.
     */
    public function test_the_cleanup_survives_a_missing_tree(): void
    {
        $this->assertSame(
            [],
            TestCase::purgeFakeDiskRootsOwnedBy(
                sys_get_temp_dir().'/fake-disk-tree-does-not-exist-'.getmypid(),
                TestCase::fakeDiskToken(),
            ),
        );

        $tree = $this->makeTempTree();

        try {
            (new Filesystem)->ensureDirectoryExists($tree.'/media');

            // No `_test_<token>` directory at all: a process that faked nothing.
            $this->assertSame([], TestCase::purgeFakeDiskRootsOwnedBy($tree, TestCase::fakeDiskToken()));
            $this->assertDirectoryExists($tree.'/media');
        } finally {
            (new Filesystem)->deleteDirectory($tree);
        }
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * Start a second, concurrent `php artisan test` run of the probe class and
     * return its decoded report.
     *
     * A real `artisan test` invocation on purpose, not `phpunit` with a hand-set
     * environment: the child then boots through exactly the entry point and
     * configuration this process itself used, including `phpunit.xml`'s pinned
     * `APP_KEY`/`JWT_SECRET` and its SQLite `:memory:` database. There is no
     * environment to drift out of sync, and the child cannot reach a developer's
     * Postgres.
     *
     * @return array{pid: int, token: string, root: string, tree_before: list<string>, tree_after: list<string>}
     */
    private function runConcurrentSuite(): array
    {
        // `sys_get_temp_dir()`, not the storage tree: this report is transient
        // and must not be able to survive as residue under
        // `storage/framework/testing/`, which WP-11 and the end-of-process sweep
        // both keep empty.
        $reportPath = sys_get_temp_dir().'/fake-disk-probe-'.getmypid().'.json';

        try {
            $process = new Process(
                [PHP_BINARY, base_path('artisan'), 'test', self::PROBE],
                base_path(),
                ['FAKE_DISK_PROBE_REPORT' => $reportPath],
            );

            $process->run();

            $this->assertTrue(
                $process->isSuccessful(),
                'the concurrent suite failed: '.$process->getErrorOutput().$process->getOutput(),
            );

            $this->assertFileExists($reportPath, 'the probe did not report anything');

            $report = json_decode((string) file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);

            $this->assertIsArray($report, 'the probe report is malformed');

            return $report;
        } finally {
            (new Filesystem)->delete($reportPath);
        }
    }

    /**
     * A throwaway stand-in for `storage/framework/testing/disks`.
     *
     * In the system temp dir, not under `storage/`, on purpose: the sweep under
     * test deletes directories, and the counter-example in that test is a root
     * carrying a FOREIGN token — the one thing that must survive. Making that
     * claim about a real directory under `storage/framework/testing/` would be
     * asserting it about a live suite's files. Being outside the repo also keeps
     * the "a run leaves no residue" guarantee free of anything this test creates.
     */
    private function makeTempTree(): string
    {
        $tree = sys_get_temp_dir().'/fake-disk-tree-'.getmypid();

        (new Filesystem)->deleteDirectory($tree);
        (new Filesystem)->ensureDirectoryExists($tree);

        return $tree;
    }

    /**
     * Every path currently below `$root`, relative to it and sorted.
     *
     * @return list<string>
     */
    private function listTree(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $paths = [];
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            $paths[] = substr($entry->getPathname(), strlen($prefix));
        }

        sort($paths);

        return $paths;
    }
}
