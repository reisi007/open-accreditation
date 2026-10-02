<?php

namespace App\Http\Resources;

use App\Models\FailedJob;
use App\Support\QueuedMailPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * A dead-lettered mandant mail, as the DLQ-admin view needs it.
 *
 * Deliberately NOT the raw row: the `payload` blob embeds the serialized mail
 * (applicant and recipient data) and the `exception` can be arbitrarily long.
 * The mail's identity is read through {@see QueuedMailPayload}, which never
 * revives the mail, so no model is re-fetched to render this list.
 *
 * `mandant_id` is part of the response on purpose: it is the same mandant the
 * requester is already scoped to (`mandant_admin` only ever sees his own; a
 * `super_admin` legitimately sees all). No foreign mandant can appear.
 *
 * @mixin FailedJob
 */
class FailedMailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mail = QueuedMailPayload::mailJob($this->payload);

        return [
            'id' => $this->id,
            'mandant_id' => $this->mandant_id,
            'mailable' => $mail?->mailableClass,
            'recipient' => $mail?->recipient,
            'queue' => $this->queue,
            'exception' => Str::limit($this->exception, 500),
            'failed_at' => $this->failed_at?->toIso8601String(),
        ];
    }
}
