<?php

namespace App\Queue\Failed;

use App\Support\QueuedMailPayload;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;

/**
 * Position 45 (2026-10-02): the dead-letter store, with the owning mandant.
 *
 * `failed_jobs` has no mandant column in stock Laravel, and the
 * DLQ-admin surface must be mandant-isolated: a `mandant_admin` may only ever
 * see the dead letters of HIS mandant, while a `super_admin` sees all. Winning
 * that scope out of the `payload` blob at read time would repeat a fragile
 * decode on every list request, so the column is filled ONCE, when the job is
 * dead-lettered, from the job's own `mandantId` (see
 * {@see QueuedMailPayload::mandantId()}).
 *
 * Extends the framework's `database-uuids` provider instead of replacing it, so
 * `queue:failed` / `queue:retry` / `queue:forget` keep working unchanged. The
 * parent writes the row; this subclass then stamps `mandant_id`. Two statements
 * on the failure path only — never on the delivery path.
 */
final class MandantAwareFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    /**
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @param  \Throwable  $exception
     * @return string|null
     */
    public function log($connection, $queue, $payload, $exception)
    {
        $uuid = parent::log($connection, $queue, $payload, $exception);

        $mandantId = QueuedMailPayload::mandantId($payload);

        if ($mandantId !== null) {
            $this->getTable()->where('uuid', $uuid)->update(['mandant_id' => $mandantId]);
        }

        return $uuid;
    }
}
