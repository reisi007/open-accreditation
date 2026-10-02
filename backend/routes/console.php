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
