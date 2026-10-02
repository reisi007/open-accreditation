<?php

namespace App\Console\Commands;

use App\Mail\DeadlineReminderMail;
use App\Models\Accreditation;
use App\Services\MandantMailerService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
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
 *
 * **Memory (WP-10-a).** The run is system-wide — every mandant, not the
 * current one — and it used to materialise every accreditation in the window
 * (plus its eager-loaded mandant) and then, per accreditation, every matching
 * application. Both sides are read in `chunkById` batches and dispatched
 * inside the batch callback now, so the peak is one batch instead of the whole
 * window. The dedup key is per application+deadline, so the batched dispatch is
 * identical to the collected one.
 */
class SendReminders extends Command
{
    /**
     * Accreditations per `chunkById` batch (each with its eager-loaded mandant,
     * used for the mandant-specific mail transport).
     */
    private const ACCREDITATION_CHUNK_SIZE = 100;

    /**
     * Applications per `chunkById` batch, per accreditation. Bounded by one
     * accreditation's applicant count rather than by the installation, but an
     * open call for a large league is thousands of rows — and each of them
     * eagerly loads its user.
     */
    private const APPLICATION_CHUNK_SIZE = 200;

    protected $signature = 'reminders:send';

    protected $description = 'Send deadline reminders for requested applications whose accreditation deadline ends within the next 3 days';

    public function handle(MandantMailerService $mailer): int
    {
        $from = now()->startOfDay();
        $to = now()->addDays(3)->endOfDay();

        $sent = 0;

        Accreditation::query()
            ->active()
            ->whereNotNull('deadline_end')
            ->where('deadline_end', '>=', $from)
            ->where('deadline_end', '<=', $to)
            ->with('mandant')
            ->orderBy('id')
            ->chunkById(self::ACCREDITATION_CHUNK_SIZE, function (Collection $accreditations) use ($mailer, &$sent): void {
                foreach ($accreditations as $accreditation) {
                    $deadlineKey = (string) $accreditation->deadline_end?->toDateString();

                    $accreditation->applications()
                        ->where('status', 'requested')
                        ->with('user:id,email,name')
                        ->orderBy('id')
                        ->chunkById(self::APPLICATION_CHUNK_SIZE, function (Collection $applications) use ($accreditation, $mailer, $deadlineKey, &$sent): void {
                            foreach ($applications as $application) {
                                if (! Cache::add("reminders:app:{$application->id}:{$deadlineKey}", true, now()->addDay())) {
                                    continue;
                                }

                                $mailer->send($accreditation->mandant, new DeadlineReminderMail($application));

                                $sent++;
                            }
                        });
                }
            });

        // "queued", not "sent": since Position 45 `send()` only dispatches the
        // delivery job. The worker is what actually dials the relay, and a
        // failing delivery ends in the dead-letter queue, not in this counter.
        $this->info("Reminder run finished ({$sent} mail(s) queued).");

        return self::SUCCESS;
    }
}
