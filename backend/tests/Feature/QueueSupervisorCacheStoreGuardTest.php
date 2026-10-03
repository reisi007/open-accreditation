<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Befund 4 (2026-10-03): the production supervisor's fail-closed guard on
 * `CACHE_STORE` — the seventh guard, and the one that makes the mail
 * idempotency claim mean anything.
 *
 * ## Why the guard has to exist at all
 *
 * `App\Jobs\SendMandantMail` suppresses duplicate delivery with a test-and-set
 * claim on the default cache store (`Cache::add('mail-delivery:{deliveryId}',
 * true, CLAIM_TTL_SECONDS)`). The claim only suppresses anything if two
 * properties hold, and only ONE of them used to be checked:
 *
 *  - **atomic** — `Repository::add()` only delegates to `Store::add()` when a
 *    TTL is given (that is why the job passes one), and every candidate store
 *    below implements it as a single conditional write;
 *  - **shared** — `MandantMailerService::send()` runs in the FPM process and
 *    `handle()` in the worker process. `array` is per-process, `file` is
 *    per-container, `null` has no `add()` at all.
 *
 * With a per-process store two workers both claim the same delivery, both send,
 * and the second delivery leaves neither a `failed_jobs` row nor a log line —
 * the silent shape of exactly the duplicate the claim exists to prevent, in a
 * deployment that looks healthy. The supervisor already fails closed on
 * `QUEUE_CONNECTION` and `DB_QUEUE_CONNECTION` for precisely this class of
 * misconfiguration; this is the same reasoning applied to the store.
 *
 * ## What this test measures, and how
 *
 * It EXECUTES `deployment/backend-supervisor.sh` with a controlled environment.
 * Both branches die inside the guard chain, before the script removes PID
 * files, spawns a worker or touches `php` — so the test has no side effect on
 * the machine it runs on and needs no stubbed `PATH`.
 *
 * The "accepted" branch uses a sentinel instead: `CACHE_STORE` is given a value
 * the guard must accept AND `QUEUE_WORKER_TIMEOUT` is set equal to
 * `DB_QUEUE_RETRY_AFTER`, which trips the NEXT guard. A correct script answers
 * with the timeout message; one that also refused `database` would answer with
 * the cache message and fail here. That is what keeps the guard from being able
 * to brick the stack the compose file actually ships.
 */
class QueueSupervisorCacheStoreGuardTest extends TestCase
{
    /**
     * The store the deployment ships (`docker-compose.yml`,
     * `${CACHE_STORE:-database}`) and the only ones that are shared AND atomic.
     *
     * @return list<string>
     */
    private const SHARED_STORES = ['database', 'redis', 'memcached', 'dynamodb'];

    /* ------------------------------------------------------------------ */
    /* Refusals */
    /* ------------------------------------------------------------------ */

    #[DataProvider('processLocalStores')]
    public function test_the_supervisor_refuses_a_cache_store_the_claim_cannot_reach_across_processes(string $store): void
    {
        [$exitCode, $output] = $this->runSupervisor(['CACHE_STORE' => $store]);

        $this->assertSame(
            1,
            $exitCode,
            "CACHE_STORE={$store} must abort the supervisor: the delivery claim would be process-local.",
        );

        $this->assertStringContainsString(
            'CACHE_STORE',
            $output,
            "The refusal for CACHE_STORE={$store} must name the variable that has to change.",
        );

        $this->assertStringContainsString(
            'Idempotenz-Claim',
            $output,
            'The refusal must say WHICH guarantee is lost, or an operator cannot tell a
            unrelated misconfiguration from the one this guard is about.',
        );

        // The script must abort in the guard chain, i.e. before it starts the
        // worker loops: a refusal that still came from a running supervisor
        // would be a different (and much worse) finding.
        $this->assertStringNotContainsString(
            'Queue supervisor and scheduler started',
            $output,
            'The script must die in the guard chain, before the worker and scheduler loops start.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function processLocalStores(): array
    {
        return [
            // Configured in `config/cache.php`, and the one the suite and the E2E
            // job pin: per process.
            'array' => ['array'],
            // Configured, atomic (LockableFile) — but only inside one container
            // or replica, which is the objection.
            'file' => ['file'],
            // Same objection, same configured family.
            'storage' => ['storage'],
            // Not configured in `config/cache.php`, and that is the point: the
            // framework's NullStore has no `add()` at all, so the claim would die
            // with a BadMethodCallException instead of suppressing anything.
            'null' => ['null'],
            // Fail-closed means unknown too: a store nobody audited here must not
            // pass silently.
            'a store this guard has never heard of' => ['some_custom_store'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Non-refusals */
    /* ------------------------------------------------------------------ */

    #[DataProvider('sharedStores')]
    public function test_the_shared_stores_pass_the_cache_guard_and_die_at_the_next_one(string $store): void
    {
        // The sentinel: the timeout guard (Detail 3) trips, so the script stops
        // deterministically — and the message it stops with tells us which guard
        // it reached LAST.
        [$exitCode, $output] = $this->runSupervisor([
            'CACHE_STORE' => $store,
            'QUEUE_WORKER_TIMEOUT' => '90',
            'DB_QUEUE_RETRY_AFTER' => '90',
        ]);

        $this->assertSame(1, $exitCode, 'The sentinel run must reach the timeout guard and abort there.');

        $this->assertStringContainsString(
            'QUEUE_WORKER_TIMEOUT muss kleiner als DB_QUEUE_RETRY_AFTER sein',
            $output,
            "CACHE_STORE={$store} must be accepted, so the run has to end at the NEXT guard (the timeout sentinel).",
        );

        $this->assertStringNotContainsString(
            'CACHE_STORE',
            $output,
            "CACHE_STORE={$store} is in the accepted set and must not produce a cache-store refusal.",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sharedStores(): array
    {
        return [
            'database (the shipped default)' => ['database'],
            'redis' => ['redis'],
            'memcached' => ['memcached'],
            'dynamodb' => ['dynamodb'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* The guard must fit the deployment it protects */
    /* ------------------------------------------------------------------ */

    public function test_the_cache_store_the_compose_file_ships_is_accepted(): void
    {
        $compose = $this->deploymentFile('docker-compose.yml');
        $this->assertIsString($compose, 'PREMISE: deployment/docker-compose.yml must be readable.');

        preg_match('/^\s*CACHE_STORE:\s*\$\{CACHE_STORE:-([^}]+)\}/m', $compose, $matches);

        $this->assertNotEmpty(
            $matches,
            'PREMISE: the compose file must pin CACHE_STORE with a default — a scan that finds
            no default would pass this test vacuously.',
        );

        $this->assertContains(
            $matches[1],
            self::SHARED_STORES,
            'The compose default must be a store the supervisor accepts, or the guard
            refuses the deployment this repo ships.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Run the supervisor with a controlled environment and return
     * `[exitCode, combined output]`.
     *
     * Every guard needs a value to check, so the environment starts from one
     * that passes all of them and overrides only what a test is about.
     *
     * Two safety measures, both for the case where the guard is GONE (the
     * regression this test exists for) and the script therefore falls through
     * into its worker/scheduler loops:
     *
     *  - `php` and `php-fpm` are shadowed by stubs on `PATH`. Without them a
     *    falling-through script would start a REAL `queue:work` and a REAL
     *    `schedule:run` against whatever database this checkout happens to be
     *    configured for — a test with side effects outside the test. With them
     *    the script cannot do anything except loop over two no-ops.
     *  - whatever a run leaves behind is killed afterwards. MEASURED (2026-10-03,
     *    first version of this test): with the guard deleted it left FIVE
     *    `sh backend-supervisor.sh` restart loops and three REAL workers alive on
     *    the host long after the test had "finished" — a restart loop outliving
     *    its parent is what it is FOR, which is also why a test must not leave
     *    one behind. The sweep matches the script path, so it kills the loops
     *    themselves; an in-flight `sleep` of a killed loop can outlive the sweep
     *    by a few seconds (measured: two, both gone before the next command),
     *    and it cannot spawn anything, which is the only property that matters.
     *
     * @param  array<string, string>  $overrides
     * @return array{int, string}
     */
    private function runSupervisor(array $overrides): array
    {
        $script = $this->deploymentPath('backend-supervisor.sh');
        $this->assertFileIsReadable(
            $script,
            'PREMISE: deployment/backend-supervisor.sh must be readable — an unreadable script would pass nothing.',
        );

        $process = new Process(['sh', $script], base_path(), array_merge([
            'QUEUE_CONNECTION' => 'database',
            'DB_CONNECTION' => 'pgsql',
            'DB_QUEUE_CONNECTION' => 'pgsql',
            'QUEUE_WORKER_TIMEOUT' => '60',
            'QUEUE_WORKER_RESTART_DELAY' => '5',
            'DB_QUEUE_RETRY_AFTER' => '90',
            'CACHE_STORE' => 'database',
            'PATH' => $this->stubDirectory().':'.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ], $overrides));

        $process->setTimeout(20);

        $timedOut = null;

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            $timedOut = $exception;
        } finally {
            $this->killLeftoversOf($script);
        }

        $output = $process->getOutput().$process->getErrorOutput();

        if ($timedOut !== null) {
            // Not a hang of the test: a script that no longer stops at a guard.
            throw new RuntimeException(
                'The supervisor did not abort within 20s — it must fail closed in the guard
                chain before it starts the worker and scheduler loops. Output was: '.$output,
                previous: $timedOut,
            );
        }

        return [$process->getExitCode() ?? -1, $output];
    }

    /**
     * A directory holding no-op `php` and `php-fpm`, created once per test run.
     *
     * Only the two binaries the supervisor's post-guard half calls are stubbed —
     * `sh`, `sleep` and the test runner's own PHP are untouched.
     */
    private function stubDirectory(): string
    {
        static $directory = null;

        if ($directory !== null) {
            return $directory;
        }

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'accriditation-supervisor-stubs';

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        foreach (['php', 'php-fpm'] as $binary) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.$binary, "#!/bin/sh\nexit 0\n");
            chmod($directory.DIRECTORY_SEPARATOR.$binary, 0o755);
        }

        return $directory;
    }

    /**
     * Kill every process still running the supervisor script after a run.
     *
     * Matching on the script path is specific enough: it lives in this checkout,
     * and the only thing in this repository that starts that script is this test.
     */
    private function killLeftoversOf(string $script): void
    {
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlineFile) {
            $pid = (int) basename(dirname($cmdlineFile));

            if ($pid === getmypid()) {
                continue;
            }

            $cmdline = @file_get_contents($cmdlineFile);

            if (! is_string($cmdline) || ! str_contains($cmdline, $script)) {
                continue;
            }

            @posix_kill($pid, SIGKILL);
        }
    }

    private function deploymentPath(string $name): string
    {
        return dirname(base_path()).DIRECTORY_SEPARATOR.'deployment'.DIRECTORY_SEPARATOR.$name;
    }

    private function deploymentFile(string $name): ?string
    {
        $path = $this->deploymentPath($name);

        if (! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }
}
