<?php

namespace App\Console\Commands;

use App\Services\AllocationService;
use Illuminate\Console\Command;

/**
 * P3c automatic allocation trigger. Processes every active auto-approve
 * accreditation whose deadline has passed (see `AllocationService::runAuto
 * Allocations`). Registered hourly in `routes/console.php`; idempotent, so
 * re-runs are harmless.
 */
class RunAllocations extends Command
{
    protected $signature = 'allocation:run';

    protected $description = 'Run auto-allocations for expired auto-approve accreditations';

    public function handle(AllocationService $service): int
    {
        $results = $service->runAutoAllocations();

        foreach ($results as $accreditationId => $counts) {
            $this->line(sprintf(
                'Accreditation %d: %d approved, %d denied.',
                $accreditationId,
                $counts['approved'],
                $counts['denied'],
            ));
        }

        $this->info('Allocation run finished ('.count($results).' accreditations processed).');

        return self::SUCCESS;
    }
}
