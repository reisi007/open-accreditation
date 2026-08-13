<?php

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
Schedule::command('allocation:run')->hourly()->withoutOverlapping();
