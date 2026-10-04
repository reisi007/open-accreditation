<?php

namespace Tests\Support;

use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Tests\Feature\SubAllocationMailTest;

/**
 * A `SyncQueue` that records the DB transaction level at the moment a job is
 * PUSHED, one nested list per push (outer) / job (inner).
 *
 * Used by `SubAllocationMailTest` to measure WHERE the sub engine dispatches its
 * notification — the property that no functional assertion can see: with
 * `after_commit` a push is deferred to the commit, and under `RefreshDatabase`
 * the enclosing test transaction makes "inside" and "after" look identical. The
 * recording happens in `push()`, before any deferral, which is why this class
 * has to be a queue connection of its own (the shipped one would never be asked
 * to push at that moment).
 *
 * @see SubAllocationMailTest::test_the_notification_is_dispatched_while_the_allocating_transaction_is_still_open()
 */
final class TransactionLevelRecordingQueue extends SyncQueue
{
    /**
     * Transaction levels observed per push, in order.
     *
     * @var list<list<int>>
     */
    public array $levels = [];

    public function push($job, $data = '', $queue = null)
    {
        $this->levels[] = [DB::connection()->transactionLevel()];

        return parent::push($job, $data, $queue);
    }
}
