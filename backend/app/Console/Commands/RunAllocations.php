<?php

namespace App\Console\Commands;

use App\Services\AllocationService;
use App\Services\SubAllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * P3c/P3d automatic allocation trigger. Processes every active auto-approve
 * accreditation whose deadline has passed (see `AllocationService::runAuto
 * Allocations`) and afterwards every active auto-approve sub-accreditation
 * whose deadline has passed (see `SubAllocationService::runAutoSubAllocations`).
 * Registered hourly in `routes/console.php`; idempotent, so re-runs are
 * harmless.
 *
 * **The result is written to the application log, not only to stdout.** A
 * scheduled command runs as a separate process and Laravel discards its output
 * (`Event::execute()` hands `Process::run()` `fn () => true`), so the per-
 * accreditation progress lines below are unreachable when the task runs from the
 * scheduler. The log entry is the durable record of what the hourly run did;
 * `ScheduledTaskObserver` records only THAT it ran and whether it succeeded.
 */
class RunAllocations extends Command
{
    protected $signature = 'allocation:run';

    protected $description = 'Run auto-allocations for expired auto-approve accreditations and sub-accreditations';

    public function handle(AllocationService $service, SubAllocationService $subService): int
    {
        $startedAt = microtime(true);

        try {
            $results = $service->runAutoAllocations();

            foreach ($results as $accreditationId => $counts) {
                $this->line(sprintf(
                    'Accreditation %d: %d approved, %d denied.',
                    $accreditationId,
                    $counts['approved'],
                    $counts['denied'],
                ));
            }

            $subResults = $subService->runAutoSubAllocations();

            foreach ($subResults as $subAccreditationId => $counts) {
                $this->line(sprintf(
                    'Sub-accreditation %d: %d approved, %d denied.',
                    $subAccreditationId,
                    $counts['approved'],
                    $counts['denied'],
                ));
            }
        } catch (Throwable $e) {
            // Rethrow so the exit code is non-zero, and record it here: the
            // scheduler process cannot see this child's failure except through
            // `ScheduledTaskObserver`, which knows nothing about the reason.
            Log::error('allocation:run failed', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }

        $approved = 0;
        $denied = 0;

        foreach ($results as $counts) {
            $approved += $counts['approved'];
            $denied += $counts['denied'];
        }

        $subApproved = 0;
        $subDenied = 0;

        foreach ($subResults as $counts) {
            $subApproved += $counts['approved'];
            $subDenied += $counts['denied'];
        }

        Log::info('allocation:run finished', [
            'accreditations' => count($results),
            'sub_accreditations' => count($subResults),
            'approved' => $approved,
            'denied' => $denied,
            'sub_approved' => $subApproved,
            'sub_denied' => $subDenied,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        $this->info('Allocation run finished ('.count($results).' accreditations, '.count($subResults).' sub-accreditations processed).');

        return self::SUCCESS;
    }
}
