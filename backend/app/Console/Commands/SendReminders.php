<?php

namespace App\Console\Commands;

use App\Mail\DeadlineReminderMail;
use App\Models\Accreditation;
use App\Services\MandantMailerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * P5 deadline reminders. Sends `DeadlineReminderMail` to every applicant of a
 * `requested` application whose accreditation deadline ends within the next
 * 3 days (window: `deadline_end` in [start of today, end of today + 3 days]).
 * Scheduled daily in `routes/console.php`.
 *
 * Dedup (idempotent re-runs): each application+deadline pair is cached for
 * 24h (`reminders:app:{id}:{date}`), so running the command twice within a
 * day sends each reminder exactly once. The daily schedule provides the
 * intended per-day reminder cadence; once the deadline window passes the
 * allocation engine moves the application out of `requested` anyway. A
 * DB-backed `reminder_sent_at` marker is a documented follow-up if per-window
 * "one reminder total" semantics is ever required.
 */
class SendReminders extends Command
{
    protected $signature = 'reminders:send';

    protected $description = 'Send deadline reminders for requested applications whose accreditation deadline ends within the next 3 days';

    public function handle(MandantMailerService $mailer): int
    {
        $from = now()->startOfDay();
        $to = now()->addDays(3)->endOfDay();

        $accreditations = Accreditation::query()
            ->active()
            ->whereNotNull('deadline_end')
            ->where('deadline_end', '>=', $from)
            ->where('deadline_end', '<=', $to)
            ->with('mandant')
            ->get();

        $sent = 0;

        foreach ($accreditations as $accreditation) {
            $deadlineKey = (string) $accreditation->deadline_end?->toDateString();

            $applications = $accreditation->applications()
                ->where('status', 'requested')
                ->with('user:id,email,name')
                ->get();

            foreach ($applications as $application) {
                if (! Cache::add("reminders:app:{$application->id}:{$deadlineKey}", true, now()->addDay())) {
                    continue;
                }

                $mailer->send($accreditation->mandant, new DeadlineReminderMail($application));

                $sent++;
            }
        }

        $this->info("Reminder run finished ({$sent} mail(s) sent).");

        return self::SUCCESS;
    }
}
