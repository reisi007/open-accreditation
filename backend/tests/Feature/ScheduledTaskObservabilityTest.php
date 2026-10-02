<?php

namespace Tests\Feature;

use App\Support\ScheduledTaskObserver;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Position 45, Reparaturrunde (2026-10-02): the scheduled work must be
 * OBSERVABLE. It was not, for two measured reasons in Laravel 13:
 *
 *  - A scheduled command runs as a **separate process** and its output is
 *    discarded (`Event::execute()` passes `fn () => true` as the output
 *    handler), so `RunAllocations`' per-accreditation progress lines went
 *    nowhere.
 *  - `schedule:run` **exits 0 even when a task fails**
 *    (`ScheduleRunCommand::runEvent()` throws, `handle()` catches + reports +
 *    returns), so the `if ! php artisan schedule:run` branch in
 *    `deployment/backend-supervisor.sh` could never fire for a broken task.
 *
 * `ScheduledTaskObserver` attaches `onSuccess`/`onFailure` to each task. Those
 * are `then()` callbacks that run in the SCHEDULER process, keyed on the
 * child's exit code — they need neither the child's stdout nor a non-zero
 * `schedule:run`.
 */
class ScheduledTaskObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * The failure path, end to end through `schedule:run`.
     *
     * `Schedule::exec('exit 7')` is a scheduled task that really fails, without
     * spawning anything that would touch a database — the production tasks are
     * deliberately NOT due in this test (the frozen clock sits at minute 37, so
     * neither `hourly()` (`0 * * * *`) nor `daily()` (`0 0 * * *`) fires).
     *
     * MUTATION: drop the `ScheduledTaskObserver::watch()` calls in
     * `routes/console.php` and this fails with "Failed asserting that false is
     * true" on the `Log::error` expectation.
     */
    public function test_a_failing_scheduled_task_is_recorded_in_the_application_log(): void
    {
        Log::spy();

        Carbon::setTestNow(Carbon::parse('2026-10-02 12:37:00'));

        ScheduledTaskObserver::watch(
            $this->schedule()->exec('exit 7')->everyMinute(),
            'probe:failing',
        );

        $exit = Artisan::call('schedule:run');

        $this->assertSame(0, $exit, 'schedule:run reports success for a failed task — measured, and the reason the observer exists');

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Scheduled task failed'
                && $context['task'] === 'probe:failing'
                && $context['exit_code'] === 7);
    }

    /**
     * The counterpart: a successful task leaves a HEARTBEAT. Without it,
     * "the hourly allocation run is broken" and "the scheduler has not run in
     * three days" are the same silence — and a green container is compatible
     * with both.
     */
    public function test_a_successful_scheduled_task_leaves_a_heartbeat(): void
    {
        Log::spy();

        Carbon::setTestNow(Carbon::parse('2026-10-02 12:37:00'));

        ScheduledTaskObserver::watch(
            $this->schedule()->exec('exit 0')->everyMinute(),
            'probe:ok',
        );

        Artisan::call('schedule:run');

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Scheduled task finished'
                && $context['task'] === 'probe:ok'
                && $context['exit_code'] === 0);
    }

    /**
     * The PRODUCTION registrations must carry the observer, not the probes
     * above. `Event::finish()` is the very method `Event::run()` calls after the
     * child exits, so driving it directly is the real callback path — and it
     * spawns no subprocess.
     *
     * MUTATION: revert `routes/console.php` to a bare
     * `Schedule::command('allocation:run')` and both tasks fail here.
     */
    public function test_both_production_scheduled_tasks_report_their_outcome(): void
    {
        foreach (['allocation:run', 'reminders:send'] as $task) {
            Log::spy();

            $event = $this->scheduledEvent($task);

            $this->assertInstanceOf(Event::class, $event, "the scheduled task {$task} disappeared from routes/console.php");

            $event->finish($this->app, 1);
            Log::shouldHaveReceived('error')
                ->withArgs(fn (string $message, array $context): bool => $message === 'Scheduled task failed'
                    && $context['task'] === $task
                    && $context['exit_code'] === 1);

            Log::spy();
            $event->finish($this->app, 0);
            Log::shouldHaveReceived('info')
                ->withArgs(fn (string $message, array $context): bool => $message === 'Scheduled task finished'
                    && $context['task'] === $task);
        }
    }

    /**
     * The command writes WHAT it did into the application log, because its
     * stdout is discarded by the scheduler. Run in-process here (no scheduler),
     * which is the same code path — `Artisan::call('allocation:run')` invokes
     * `handle()` exactly as the child process does.
     */
    public function test_the_allocation_run_records_what_it_did_in_the_application_log(): void
    {
        Log::spy();

        $this->assertSame(0, Artisan::call('allocation:run'));

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'allocation:run finished'
                && array_key_exists('approved', $context)
                && array_key_exists('denied', $context)
                && array_key_exists('accreditations', $context)
                && array_key_exists('sub_accreditations', $context));
    }

    /**
     * A broken allocation run must be visible in the log AND report a non-zero
     * exit code — the second half is what a health check or a `&&` chain can
     * act on.
     *
     * The failure is injected at the query-event boundary — the closest thing to a
     * "genuine" failure that is PORTABLE. The obvious alternative,
     * `Schema::drop('sub_accreditations')`, is not: SQLite drops a table with
     * foreign-key dependents without complaint, PostgreSQL 17 refuses
     * (`SQLSTATE[2BP01] Dependent objects still exist … sub_applications_…_foreign`)
     * — exactly the class of divergence AGENTS.md §2 forbids. Both services are
     * `final` as well, so neither can be mocked.
     *
     * MUTATION: swallow the exception instead of rethrowing it and the
     * `assertInstanceOf` fails (the command would report SUCCESS); drop the
     * `Log::error` and the spy fails.
     */
    public function test_a_failing_allocation_run_is_logged_and_reports_failure(): void
    {
        Log::spy();

        DB::listen(static function (): void {
            throw new RuntimeException('the allocation run cannot reach its data');
        });

        $escaped = null;

        try {
            Artisan::call('allocation:run');
        } catch (Throwable $e) {
            $escaped = $e;
        }

        // An exception that ESCAPES is what makes the CLI exit non-zero:
        // `Illuminate\Foundation\Console\Kernel::handle()` catches, renders and
        // `return 1` (`vendor/…/Console/Kernel.php:199-205`). That 1 is the
        // child's exit code, and it is what the observer reacts to — so this
        // link must not be swallowed here.
        $this->assertInstanceOf(
            RuntimeException::class,
            $escaped,
            'a failed allocation run must not be swallowed into a SUCCESS return',
        );

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context): bool => $message === 'allocation:run failed'
                && $context['exception'] === RuntimeException::class
                && $context['error'] === 'the allocation run cannot reach its data');
    }

    private function schedule(): Schedule
    {
        return $this->app->make(Schedule::class);
    }

    private function scheduledEvent(string $command): ?Event
    {
        foreach ($this->schedule()->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        return null;
    }
}
