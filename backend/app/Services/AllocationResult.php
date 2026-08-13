<?php

namespace App\Services;

use JsonSerializable;

/**
 * The outcome of one allocation run on a single accreditation.
 *
 * - `approved`: applications newly set to `approved`
 * - `denied`: applications newly set to `denied` — either quota surplus
 *   (reason `Quota erschöpft`) or blacklist matches (reason `Blacklist`,
 *   which also count towards `skipped_blacklist`)
 * - `skipped_blacklist`: applications excluded because their user is on the
 *   mandant's blacklist. In `approveSelection` these stay `requested` (the
 *   admin may lift the blacklist later), in `approveAllEligible` they are
 *   denied with reason `Blacklist`.
 */
final class AllocationResult implements JsonSerializable
{
    public function __construct(
        public readonly int $approved,
        public readonly int $denied,
        public readonly int $skipped_blacklist,
    ) {}

    public static function none(): self
    {
        return new self(0, 0, 0);
    }

    /**
     * @return array{approved: int, denied: int, skipped_blacklist: int}
     */
    public function toArray(): array
    {
        return [
            'approved' => $this->approved,
            'denied' => $this->denied,
            'skipped_blacklist' => $this->skipped_blacklist,
        ];
    }

    /**
     * @return array{approved: int, denied: int, skipped_blacklist: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
