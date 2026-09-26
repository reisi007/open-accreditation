<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * WP-11 — the test suite must be a pure function of the code, never of the
 * leftovers of an earlier run.
 *
 * Two independent defects are pinned here, both of which made the suite
 * history-dependent in the same checkout:
 *
 * 1. `Storage::fake()` was called by ~20 test classes only. Every test that
 *    did NOT call it resolved the REAL disk roots from `config/filesystems.php`
 *    — `storage/app/private` (both `local` and `private`) and `storage/app/media`
 *    — and wrote actual files there. Those roots are shared, gitignored and
 *    never cleaned, so a run silently deposited files into the developer's dev
 *    media and every later run could still see them.
 * 2. Nothing emptied `storage/framework/testing/disks/` between runs, so
 *    whatever the last test wrote survived into the next run.
 *
 * `Tests\TestCase` fixes both by faking all four local disks in `setUp()` and
 * purging their roots in `tearDown()`. These tests are the guard. Both calls
 * were mutation-checked (delete one call, run this class, count the reds):
 *
 * The *other* race on the same directory — two concurrent full runs sharing one
 * fake root, which is what made three agents lose up to 243 tests each — is a
 * separate defect with a separate guard, in `FakeDiskProcessIsolationTest`.
 * WP-11 is about a run not depending on an EARLIER run; that one is about a run
 * not depending on a CONCURRENT one. This class stays deliberately blind to it:
 * its assertions are about emptiness and provenance, which hold either way.
 *
 *  - `setUp()`'s `rootEveryDiskInTheTestTree()` removed → **THREE of the six**
 *    tests fail. The disk roots are the production ones, a write lands in
 *    `storage/app/**`, and `setUp()` no longer scrubs the leftovers either.
 *    (Four today, because the `tearDown()` guard below also observes the
 *    residue that survives from one test into the next.)
 *  - `tearDown()`'s `purgeFakeDiskRoots()` removed → **NONE of the six** failed.
 *    A test body cannot observe its own `tearDown()`; the next test cannot see
 *    what this one left, because `setUp()`'s `Storage::fake()` empties the roots
 *    before any body runs; and `test_residue_of_a_finished_test_is_scrubbed_in_
 *    tear_down` calls the purge from the test body, so it exercises the METHOD,
 *    not the WIRING. The `tearDown()` override at the bottom of this class
 *    closes that hole — it now takes **two** of the six tests red (every test
 *    that leaves content behind: the three `plantResidue()` ones and the
 *    `Storage::disk('media')->put()` one).
 *
 * `snapshotProductionRoots()` is content-based (SHA-256 per file), so the
 * verdict depends only on what THIS test wrote — not on whatever a previous
 * developer run or a crashed test happened to leave in the tree.
 */
class TestDiskIsolationTest extends TestCase
{
    /**
     * The production disk roots a test must never touch. `local` and `private`
     * deliberately share the first one (`config/filesystems.php`).
     */
    private const PRODUCTION_ROOTS = ['app/private', 'app/media', 'app/public'];

    private const RESIDUE_FILE = 'wp11-residue/planted-by-a-previous-run.txt';

    public function test_every_local_disk_is_rooted_in_the_test_tree(): void
    {
        $testingTree = storage_path('framework/testing/disks');

        foreach (self::FAKE_DISKS as $disk) {
            $root = $this->fakeDiskRoots()[$disk] ?? '';

            $this->assertNotSame('', $root, "disk [{$disk}] was not faked in setUp()");
            $this->assertStringStartsWith(
                $testingTree.DIRECTORY_SEPARATOR,
                $root.DIRECTORY_SEPARATOR,
                "disk [{$disk}] must be rooted in the throwaway test tree, got [{$root}]",
            );
        }
    }

    /**
     * The four faked disks stay four DISTINCT roots.
     *
     * Not a cross-process concern, and not affected by the per-process token
     * suffix — it guards the opposite failure: a token scheme that collapsed the
     * disk name (`Storage::fake()` appends its token to the *disk* name, so a
     * token ending in a separator or containing one would merge
     * `…/disks/media` and `…/disks/public` into one directory). Two test classes
     * writing "their" media would then delete each other's files inside a single
     * process, which no amount of per-process isolation would prevent.
     */
    public function test_the_faked_roots_are_distinct_per_disk(): void
    {
        $roots = array_values($this->fakeDiskRoots());

        $this->assertSame(
            count($roots),
            count(array_unique($roots)),
            'the faked disks share a root, so one disk\'s fake() empties another\'s files: '.implode(', ', $roots),
        );

        foreach (self::FAKE_DISKS as $disk) {
            $this->assertStringEndsWith(
                DIRECTORY_SEPARATOR.$disk.'_test_'.TestCase::fakeDiskToken(),
                $this->fakeDiskRoots()[$disk],
                "disk [{$disk}] must keep its own name in the root, got [{$this->fakeDiskRoots()[$disk]}]",
            );
        }
    }

    public function test_writing_through_the_disks_never_reaches_the_production_roots(): void
    {
        $before = $this->snapshotProductionRoots();

        // The exact operations that used to land in storage/app/** …
        Storage::disk('private')->put('wp11-guard/user-media/1/portrait/portrait.png', 'portrait-bytes');
        Storage::disk('media')->put('wp11-guard/verband-a.test/logo.png', 'logo-bytes');
        Storage::disk('media')->put('wp11-guard/_tenants/1/logo.webp', 'logo-webp-bytes');
        Storage::disk('local')->put('wp11-guard/mandants/verband-a/logo.png', 'legacy-logo-bytes');
        Storage::disk('public')->put('wp11-guard/avatars/1.png', 'avatar-bytes');

        $this->assertSame(
            $before,
            $this->snapshotProductionRoots(),
            'A test wrote into storage/app/** — that is the developer\'s real dev media, '
            .'and its leftovers made the next run depend on this one.',
        );
    }

    /**
     * The `setUp()` half: leftovers of a previous (or crashed) run are gone
     * before the test body can observe them.
     */
    public function test_residue_of_a_previous_run_is_scrubbed_before_every_test(): void
    {
        foreach ($this->fakeDiskRoots() as $disk => $root) {
            $this->plantResidue($root);
        }

        $this->assertNotSame(
            [],
            $this->residueInFakeRoots(),
            'the fixture below is broken: nothing was planted',
        );

        // Exactly what `setUp()` runs for every single test.
        $this->rootEveryDiskInTheTestTree();

        $this->assertSame(
            [],
            $this->residueInFakeRoots(),
            'fake disk roots must be empty at the start of every test',
        );
    }

    /**
     * The `tearDown()` half: what a test wrote is gone once it finished, so a
     * run leaves nothing behind for the next one.
     */
    public function test_residue_of_a_finished_test_is_scrubbed_in_tear_down(): void
    {
        foreach ($this->fakeDiskRoots() as $root) {
            $this->plantResidue($root);
        }

        // Exactly what `tearDown()` runs for every single test.
        $this->purgeFakeDiskRoots();

        $this->assertSame(
            [],
            $this->residueInFakeRoots(),
            'fake disk roots must be empty after every test',
        );
    }

    /**
     * Cross-test guarantee: this method deliberately plants residue, the next
     * one asserts it is gone. Under `--filter` the assertion stays true (an
     * empty tree is empty), so neither test depends on being run together.
     */
    public function test_a_planted_file_actually_lands_in_the_test_tree(): void
    {
        Storage::disk('media')->put(self::RESIDUE_FILE, 'planted');

        $this->assertContains(
            self::RESIDUE_FILE,
            $this->residueInFakeRoots(),
            'planting residue must reach the test tree, otherwise the two tests above prove nothing',
        );
    }

    public function test_the_next_test_does_not_see_the_planted_file(): void
    {
        $this->assertNotContains(
            self::RESIDUE_FILE,
            $this->residueInFakeRoots(),
            'a file planted by the previous test survived into this one — the suite is history-dependent',
        );
    }

    /**
     * The ONLY guard for the `tearDown()` WIRING, and it has to live here: a
     * test body cannot observe its own `tearDown()`, the NEXT test cannot see
     * what this one left (because `setUp()`'s `Storage::fake()` empties the
     * roots again before any body runs), and
     * `test_residue_of_a_finished_test_is_scrubbed_in_tear_down` calls
     * `purgeFakeDiskRoots()` from the test body — so it exercises the METHOD,
     * never the wiring. That is exactly why removing `purgeFakeDiskRoots()` from
     * `Tests\TestCase::tearDown()` turned NO test red before this check existed.
     *
     * Overriding `tearDown()` and inspecting the roots *after* delegating to the
     * parent closes that hole and stays order-independent: it holds for every
     * test of this class, and four of them genuinely leave content behind
     * (`plantResidue()` x3 and the `Storage::disk('media')->put()` above), so
     * the assertion is not vacuous.
     */
    protected function tearDown(): void
    {
        // Captured BEFORE the parent call: `parent::tearDown()` flushes the
        // application instance `fakeDiskRoots()` is read from, and the check
        // below runs on the plain filesystem rather than through the `Storage`
        // facade (a test may have replaced that facade with a mock).
        $roots = $this->fakeDiskRoots();

        parent::tearDown();

        foreach ($roots as $disk => $root) {
            $this->assertSame(
                [],
                $this->listTree($root),
                "disk [{$disk}] still had content after Tests\\TestCase::tearDown() — a run would leave "
                .'residue in the shared (gitignored) storage/framework/testing/disks tree.',
            );
        }
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function plantResidue(string $root): void
    {
        (new Filesystem)->ensureDirectoryExists(dirname($root.'/'.self::RESIDUE_FILE));
        file_put_contents($root.'/'.self::RESIDUE_FILE, 'residue');
    }

    /**
     * Every path currently below a faked disk root, relative and sorted.
     *
     * @return list<string>
     */
    private function residueInFakeRoots(): array
    {
        $found = [];

        foreach ($this->fakeDiskRoots() as $root) {
            foreach ($this->listTree($root) as $relative) {
                $found[] = $relative;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * A snapshot of the production disk roots, used to prove that no test
     * created, changed or deleted a file there. Read straight off the
     * filesystem — the disks themselves are re-rooted, which is the point of
     * the fix.
     *
     * The snapshot is keyed on the file's **content hash**, not on its size: a
     * size comparison only fails when the byte count happens to differ, so an
     * overwrite with an equally long payload — and a deletion/replacement that
     * preserves the total — slips through. A SHA-256 per file makes the guard's
     * verdict depend on what THIS test wrote alone, so it is independent of any
     * residue a previous or concurrent run happened to leave behind.
     *
     * @return array<string, array<string, string>>
     */
    private function snapshotProductionRoots(): array
    {
        $snapshot = [];

        foreach (self::PRODUCTION_ROOTS as $relative) {
            $root = storage_path($relative);
            $entries = [];

            foreach ($this->listTree($root) as $path) {
                $entries[$path] = is_file($root.'/'.$path)
                    ? (string) hash_file('sha256', $root.'/'.$path)
                    : 'directory';
            }

            ksort($entries);
            $snapshot[$relative] = $entries;
        }

        return $snapshot;
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
