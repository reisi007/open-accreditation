<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * The COUNTER-PROBE for `MailDeadLetterTest::test_the_cli_tries_does_not_lower_the_job_own_cap`.
 *
 * Deliberately a job WITHOUT `$tries` and WITHOUT `backoff()`: whatever
 * `queue:work --tries=N` says is then the ONLY cap in play, and the worker
 * dead-letters it on attempt N. That is the other half of the measured pair —
 * the CLI number really does reach a job that has no cap of its own, so the
 * "the CLI cannot lower the cap" assertion is not an artefact of a `--tries`
 * option that silently does nothing at all.
 *
 * It throws unconditionally instead of dialling a dead relay, so the probe
 * measures the WORKER's cap resolution only — not the SMTP path, which the
 * capped half of the pair already exercises.
 */
final class UncappedThrowingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        throw new RuntimeException('UncappedThrowingJob: the dead-letter probe always fails.');
    }
}
