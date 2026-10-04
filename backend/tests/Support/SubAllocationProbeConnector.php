<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;

/**
 * Queue connector that hands out ONE pre-built
 * {@see TransactionLevelRecordingQueue} — the instance the test asserts on, not
 * a fresh one per resolution (`QueueManager` caches resolved connections, but
 * an explicit `Queue::extend` resolver that news up its own queue would leave
 * the test looking at an empty recorder).
 */
final class SubAllocationProbeConnector implements ConnectorInterface
{
    public function __construct(
        private readonly Queue $queue,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): Queue
    {
        return $this->queue;
    }
}
