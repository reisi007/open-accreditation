<?php

namespace App\Http\Resources;

use App\Services\QrTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An application (Antrag) of the admin approval view (P3e), including the
 * applicant identity and a compact view of the accreditation. The nested
 * accreditation's `available` is `quota - approved_count` (the remaining quota
 * slots) — controllers load it via a scoped `withCount`.
 */
class AdminApplicationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->user !== null
                ? ['id' => $this->user->id, 'email' => $this->user->email, 'name' => $this->user->name]
                : null,
            'accreditation' => $this->accreditationData(),
            'status' => $this->status,
            'priority' => $this->priority,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toISOString(),
            // P4: the verification URL (relative — the frontend prefixes its
            // own origin), only for approved applications. Serialization NEVER
            // writes: the stored `qr_token` is used as-is, and a row without a
            // usable one (never issued, legacy v1, or unverifiable after an
            // APP_KEY rotation) falls back to the freshly computed value of
            // `QrTokenService::token()`. Repairing the column is the job of the
            // write paths (approval, resend, export, wallet pass) and of
            // `accreditation:backfill-qr-tokens` — a DB write here would be a
            // side effect of a read.
            'qr_url' => $this->status === 'approved'
                ? '/verify/'.$this->verifyToken()
                : null,
        ];
    }

    /**
     * The token of the `qr_url`: the stored one when it is a valid, tenant-bound
     * v2 token of this application, else the computed one. Both carry the same
     * claims, so the URL is always verifiable — only the column may lag behind
     * until the next write path or the backfill command repairs it.
     */
    private function verifyToken(): string
    {
        $tokens = app(QrTokenService::class);
        $stored = $this->qr_token;

        if (is_string($stored) && $stored !== '' && $tokens->isValidFor($this->resource, $stored)) {
            return $stored;
        }

        return $tokens->token($this->resource);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accreditationData(): ?array
    {
        if (! $this->relationLoaded('accreditation') || $this->accreditation === null) {
            return null;
        }

        $accreditation = $this->accreditation;

        return [
            'id' => $accreditation->id,
            'category' => $accreditation->category !== null
                ? ['id' => $accreditation->category->id, 'name' => $accreditation->category->name]
                : null,
            'scope' => $accreditation->scope,
            'event' => $accreditation->event_id !== null && $accreditation->event !== null
                ? [
                    'id' => $accreditation->event->id,
                    'title' => $accreditation->event->title,
                    'date' => $accreditation->event->date?->format('Y-m-d'),
                ]
                : null,
            'team' => $accreditation->team_id !== null && $accreditation->team !== null
                ? ['id' => $accreditation->team->id, 'name' => $accreditation->team->name]
                : null,
            'quota' => $accreditation->quota,
            'available' => (int) $accreditation->quota - (int) ($accreditation->approved_count ?? 0),
        ];
    }
}
