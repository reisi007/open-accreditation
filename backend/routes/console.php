<?php

use App\Support\ScheduledTaskObserver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// P3c: the allocation engine runs hourly so auto-approve accreditations are
// processed shortly after their deadline_end (end of day, 23:59:59) expires.
// A finer cadence can be added later; the run itself is idempotent. Runs in
// every environment (dev included — an expired accreditation must be
// allocated regardless of the environment).
//
// `ScheduledTaskObserver` is not decoration: a scheduled command runs as a
// SEPARATE process whose output Laravel throws away, and `schedule:run` exits
// 0 even when the task fails. Without the observer, "the hourly run is broken"
// and "the scheduler never ran" are the same silence — see the class docblock
// for both measurements.
ScheduledTaskObserver::watch(
    Schedule::command('allocation:run')->hourly()->withoutOverlapping(),
    'allocation:run',
);

// P5: daily deadline reminders — `requested` applications of active
// accreditations whose deadline ends within the next 3 days (dedup per
// application+deadline, see `SendReminders`). Runs daily so applicants get
// one reminder per day in the window.
ScheduledTaskObserver::watch(
    Schedule::command('reminders:send')->daily()->withoutOverlapping(),
    'reminders:send',
);

// Daily reaper for EXPIRED rows of the `database` cache store (Nutzerentscheid
// 2026-10-04). The table grows one row per delivered mail (the `SendMandantMail`
// idempotency claim, never read again) and one per invalidated JWT, and nothing
// collected them: `DatabaseStore::many()` only drops an expired row when that
// key is read, Laravel 13.33.0 has no `cache:prune`, and
// `cache:prune-stale-tags` is Redis-only.
//
// This registration replaces a standing guard
// (`SendMandantMailTest::test_an_expired_claim_is_invisible_but_its_row_survives_until_something_reads_it`)
// that forbade ANY scheduled task from touching the cache table, because that
// table carries the JWT blacklist: deleting a row that is still readable would
// un-revoke a logged-out token. The decision changed, so the guard changed with
// it — from "no cache task exists" to "the only cache task that may exist is
// this one, and it deletes only expired rows", the behavioural half of which is
// pinned with mutations in `tests/Feature/ExpiredCachePrunerTest.php`.
//
// `withoutOverlapping()` for the same reason as the two tasks above. Note that
// this task's own mutex lives in `cache_locks`, which the reaper never touches.
ScheduledTaskObserver::watch(
    Schedule::command('cache:prune-expired')->daily()->withoutOverlapping(),
    'cache:prune-expired',
);
