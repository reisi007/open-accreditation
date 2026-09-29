<?php

namespace App\Services;

use App\Exceptions\MediaRemovalFailedException;
use Illuminate\Support\Facades\Log;

/**
 * The bounded "remove every file, retry the stuck ones, report what is left"
 * pass that a row-destroying delete needs AFTER its rows are gone.
 *
 * ## Why this is one class and not two copies
 *
 * The contract has four parts and every one of them was arrived at by a measured
 * failure of the obvious implementation, so a second hand-written copy of the
 * loop would be a second, shorter answer to the same four questions:
 *
 *  1. **Bounded attempts per file.** A genuinely unremovable file (read-only
 *     bind mount, EIO) must surface quickly instead of holding a request open.
 *     The retry exists for the TRANSIENT half — an NFS hiccup, a mount that is
 *     remounted read-write in between — and every purge is idempotent (deleting
 *     an absent file reports success), so a second attempt is safe.
 *  2. **A bound on the AGGREGATE, not only on one file.** With N stuck files the
 *     total would be `N × attempts × backoff`, i.e. pure sleep, N-fold.
 *  3. **Only `MediaRemovalFailedException` is retried.** A `QueryException` from
 *     a row delete is also a `RuntimeException`, but no unlink-retry can fix a
 *     database failure — retrying it three times with a growing backoff turns
 *     one loud, immediate error into a 500 with the wrong cause attached, 150 ms
 *     later.
 *  4. **A failure never aborts the remaining purges.** The rows are already
 *     gone, so every other file is collectable right now; stopping at the first
 *     stuck file would strand all of them.
 *
 * ## What the caller has to say, and why it cannot be guessed here
 *
 * The leftovers are unreferenced orphans, but "who will collect them" is NOT a
 * property of this loop — it is a property of the disk layout the files live in,
 * and the two layouts differ:
 *
 *  - the managed public layout on the `media` disk is enumerated by
 *    `media:prune-orphans`, so a leftover really is reaped,
 *  - `user-media/**` on the `private` disk is **out of scope for that command by
 *    definition** (W5, `MediaPruneOrphansCommand`) — nobody reaps it.
 *
 * So `$leftoverAdvice` is a caller-supplied sentence that states the truth about
 * ITS files, and both log messages below reuse it verbatim. Promising a reaper
 * that does not exist is the one failure mode this class is not allowed to have:
 * an operator who is told "the reaper collects it" never cleans it up.
 */
final class MediaPurgeRunner
{
    /**
     * Attempts per media purge. Bounded: a file that is genuinely unremovable
     * must surface quickly instead of holding the request open.
     */
    public const PURGE_ATTEMPTS = 3;

    /**
     * Backoff before the second and third attempt, multiplied by the attempt
     * number (so the waits are 50 ms + 100 ms). Long enough to ride out a
     * transient filesystem error, short enough to stay invisible in a request.
     */
    public const PURGE_RETRY_DELAY_MICROSECONDS = 50_000;

    /**
     * Wall-clock budget for the WHOLE cascade, not for one file.
     *
     * `PURGE_ATTEMPTS` bounds a single purge; without an aggregate bound the
     * total is `N × 3 × backoff` on a read-only volume, where every one of the
     * N files fails and the request spends its whole time asleep. Thirty
     * seconds is far beyond any honest unlink (a single `unlink()` call) and
     * short enough to stay inside a normal gateway timeout. What is left when
     * the budget runs out is NOT lost: the rows are already deleted, so the
     * files are unreferenced orphans — the same residual the failure path
     * leaves behind anyway.
     */
    public const PURGE_TOTAL_BUDGET_SECONDS = 30.0;

    /**
     * Run every purge, retrying a raised one a bounded number of times, and
     * return the ones that never succeeded.
     *
     * @param  array<string, callable(): void>  $purges  label => the purge to run
     * @param  string  $subject  what the purges belong to, for the log lines
     *                           ("a deleted mandant", "a deleted account")
     * @param  string  $leftoverAdvice  the clause that follows "is
     *                                  unreferenced now and …" in both messages;
     *                                  must state what actually collects the
     *                                  leftover for THIS layout
     * @return array<string, MediaRemovalFailedException> label => the last failure
     */
    public function run(array $purges, string $subject, string $leftoverAdvice): array
    {
        $failed = [];
        $attempted = [];
        $deadline = hrtime(true) + (int) (self::PURGE_TOTAL_BUDGET_SECONDS * 1_000_000_000);

        foreach ($purges as $label => $purge) {
            // The aggregate deadline, checked BEFORE the first attempt of a
            // label, so a purge never starts when no budget is left to finish it.
            if (hrtime(true) > $deadline) {
                Log::error(sprintf(
                    'The media purge of %s ran out of its time budget; the remaining files are %s.',
                    $subject,
                    $leftoverAdvice,
                ), [
                    'budget_seconds' => self::PURGE_TOTAL_BUDGET_SECONDS,
                    'attempts_per_file' => self::PURGE_ATTEMPTS,
                    'skipped' => array_values(array_diff(array_keys($purges), $attempted)),
                ]);

                break;
            }

            $attempted[] = $label;

            for ($attempt = 1; ; $attempt++) {
                try {
                    $purge();

                    break;
                } catch (MediaRemovalFailedException $exception) {
                    if ($attempt >= self::PURGE_ATTEMPTS) {
                        $failed[$label] = $exception;

                        // The path is logged the moment it is known to be
                        // stuck, not only in the post-hoc summary of the whole
                        // cascade — with a read-only volume that summary can be
                        // arbitrarily far in the future, and the operator needs
                        // the concrete file now.
                        Log::error(sprintf(
                            'A media file of %s could not be removed; the leftover is unreferenced now and %s.',
                            $subject,
                            $leftoverAdvice,
                        ), [
                            'label' => $label,
                            'path' => $exception->path,
                            'attempts' => self::PURGE_ATTEMPTS,
                        ]);

                        break;
                    }

                    usleep(self::PURGE_RETRY_DELAY_MICROSECONDS * $attempt);
                }
            }
        }

        return $failed;
    }
}
