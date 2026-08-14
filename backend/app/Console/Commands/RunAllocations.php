<?php

namespace App\Console\Commands;

use App\Services\AllocationService;
use App\Services\SubAllocationService;
use Illuminate\Console\Command;

/**
 * P3c/P3d automatic allocation trigger. Processes every active auto-approve
 * accreditation whose deadline has passed (see `AllocationService::runAuto
 * Allocations`) and afterwards every active auto-approve sub-accreditation
 * whose deadline has passed (see `SubAllocationService::runAutoSubAllocations`).
 * Registered hourly in `routes/console.php`; idempotent, so re-runs are
 * harmless.
 */
class RunAllocations extends Command
{
    protected $signature = 'allocation:run';

    protected $description = 'Run auto-allocations for expired auto-approve accreditations and sub-accreditations';

    public function handle(AllocationService $service, SubAllocationService $subService): int
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

        $subResults = $subService->runAutoSubAllocations();

        foreach ($subResults as $subAccreditationId => $counts) {
            $this->line(sprintf(
                'Sub-accreditation %d: %d approved, %d denied.',
                $subAccreditationId,
                $counts['approved'],
                $counts['denied'],
            ));
        }

        $this->info('Allocation run finished ('.count($results).' accreditations, '.count($subResults).' sub-accreditations processed).');

        return self::SUCCESS;
    }
}
