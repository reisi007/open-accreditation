<?php

namespace App\Models;

use App\Queue\Failed\MandantAwareFailedJobProvider;
use App\Support\QueuedMailPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Position 45 (2026-10-02): a row of Laravel's dead-letter store.
 *
 * There is no stock model for `failed_jobs`; the queue layer writes it through
 * {@see MandantAwareFailedJobProvider}. This model exists so
 * the mandant-isolated admin surface (`GET /api/admin/failed-mails`,
 * `POST /api/admin/failed-mails/{id}/requeue`) can express its scope with the
 * ordinary query builder instead of raw SQL.
 *
 * Read + delete only: a failed job is never updated in place. Distinct from the
 * `requeue` write path, which only inserts into `jobs` and removes the
 * `failed_jobs` row.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property int|null $mandant_id
 * @property string $payload
 * @property string $exception
 * @property Carbon|null $failed_at
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    /**
     * The table carries `failed_at`, not `created_at`/`updated_at`.
     */
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'mandant_id' => 'integer',
        'failed_at' => 'datetime',
    ];

    /**
     * Whether this dead letter is an undelivered mandant mail. Only those are
     * exposed by the DLQ API; `mandant_id` is filled for them (and left null
     * for any other job), but the payload is the authoritative discriminator.
     */
    public function isMailJob(): bool
    {
        return QueuedMailPayload::mailJob($this->payload) !== null;
    }
}
