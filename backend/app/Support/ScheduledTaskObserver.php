<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;

/**
 * Position 45, Reparaturrunde (2026-10-02): makes the SCHEDULED work
 * observable, which `Schedule::command()` alone is not.
 *
 * ## What Laravel 13 actually does with a scheduled command
 *
 * `Schedule::command()` is `Schedule::exec()` with the command string
 * assembled — the task runs as a **separate process**. Two measured
 * consequences (`Illuminate\Console\Scheduling\Event::execute()` and
 * `ScheduleRunCommand::runEvent()`):
 *
 *  - The child's output is **discarded**: `Process::run()` is handed
 *    `fn () => true` as its output handler, so everything the command prints
 *    (`RunAllocations`'s per-accreditation progress lines included) goes
 *    nowhere at all.
 *  - `schedule:run` **exits 0 even when a task fails**: `runEvent()` throws an
 *    `Exception`, `handle()` catches it, reports it and returns normally.
 *
 * Together these mean the `if ! php artisan schedule:run` branch in
 * `deployment/backend-supervisor.sh` can never fire for a broken task, and a
 * scheduled run leaves no trace an operator or a health check can see.
 *
 * ## What this adds
 *
 * `onSuccess` / `onFailure` on the event run as `then()` callbacks **in the
 * scheduler process itself**, keyed on the child's exit code. They are
 * therefore independent of both problems above: they do not need the child's
 * stdout, and they do not need a non-zero `schedule:run`.
 *
 * The success callback is a heartbeat, not a luxury: it is the only positive
 * evidence that the hourly/daily work is still happening. Without it, "the
 * scheduler stopped" and "the scheduler had nothing to do" look identical.
 *
 * The scheduled command additionally logs its own result (see
 * `App\Console\Commands\RunAllocations`) — the observer records THAT IT RAN
 * and WHETHER IT SUCCEEDED; only the command knows what it actually did.
 */
final class ScheduledTaskObserver
{
    /**
     * Attach the success/failure heartbeat to a scheduled task.
     *
     * @param  Event  $event  the event returned by `Schedule::command()`
     * @param  string  $task  the task name as it appears in the log
     */
    public static function watch(Event $event, string $task): Event
    {
        $startedAt = microtime(true);

        $event->onSuccess(static function () use ($event, $task, $startedAt): void {
            Log::info('Scheduled task finished', [
                'task' => $task,
                'exit_code' => $event->exitCode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        });

        $event->onFailure(static function () use ($event, $task, $startedAt): void {
            Log::error('Scheduled task failed', [
                'task' => $task,
                'exit_code' => $event->exitCode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        });

        return $event;
    }
}
