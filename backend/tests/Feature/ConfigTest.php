<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * P7-Hardening config contracts.
 */
class ConfigTest extends TestCase
{
    /**
     * F3: the `local` disk must never serve files over HTTP — user/mandant
     * media live on the auth-gated `private` disk and are delivered only
     * through the authenticated media endpoints. A `serve => true` on the
     * shared storage root would expose them publicly.
     */
    public function test_local_disk_does_not_serve_files(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
    }
}
