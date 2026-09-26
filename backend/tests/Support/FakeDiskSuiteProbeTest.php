<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * A second, real PHPUnit process — the cross-process half of
 * `Tests\Feature\FakeDiskProcessIsolationTest`.
 *
 * Deliberately NOT part of any testsuite: `phpunit.xml` scans only `tests/Unit`
 * and `tests/Feature`, so this class never runs as part of an ordinary suite. It
 * is started on purpose by
 * `FakeDiskProcessIsolationTest::test_a_concurrent_suite_cannot_see_or_destroy_our_files`
 * as `php artisan test tests/Support/FakeDiskSuiteProbeTest.php` — a genuine
 * second `php artisan test` process, going through the very same
 * `Tests\TestCase::setUp()` as the whole suite.
 *
 * That is why this is a test class and not a hand-rolled script booting the
 * framework: a script can install the isolation itself and would then stay green
 * even if `setUp()` stopped doing so. Running the real suite wiring is what makes
 * the parent's assertion sensitive to the wiring regressing.
 *
 * It reports through the file named by `FAKE_DISK_PROBE_REPORT` because stdout
 * belongs to the test runner's own output.
 */
class FakeDiskSuiteProbeTest extends TestCase
{
    private const DISK = 'media';

    public function test_report_this_process_s_fake_disk_root(): void
    {
        $reportPath = $_SERVER['FAKE_DISK_PROBE_REPORT'] ?? '';

        $this->assertNotSame(
            '',
            $reportPath,
            'This class is a probe and must not be run as part of a suite. It reports to the file named by '
            .'FAKE_DISK_PROBE_REPORT, which only FakeDiskProcessIsolationTest sets.',
        );

        $root = $this->fakeDiskRoots()[self::DISK];

        $before = $this->listTree($root);

        Storage::disk(self::DISK)->put('child-only.txt', 'theirs');

        file_put_contents($reportPath, json_encode([
            'pid' => getmypid(),
            'token' => TestCase::fakeDiskToken(),
            'root' => $root,
            'tree_before' => $before,
            'tree_after' => $this->listTree($root),
        ], JSON_THROW_ON_ERROR));

        $this->assertStringEndsWith(
            '_test_'.TestCase::fakeDiskToken(),
            $root,
            'a plain `php artisan test` process rooted its fake disk outside its own token — this is the '
            .'cross-process race: two such processes share one root and delete each other\'s files.',
        );
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
