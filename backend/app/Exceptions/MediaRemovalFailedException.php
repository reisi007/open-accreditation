<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A referenced media file could not be removed, so its DB reference was kept
 * (R-D7).
 *
 * Thrown from exactly ONE place per service — the branch where
 * `MediaStorage::deleteWithVariants()` returned `false` — and never for a
 * storage that merely misbehaved in some other way. That is the whole point of
 * the dedicated type: `RuntimeException` is the base of everything the storage
 * layer can raise (`put()` on a `throw => false` disk, a `QueryException` from
 * the row delete that follows, a `WebpConverter` failure), so a caller that
 * retries "the file might still be removable" on a `RuntimeException` also
 * swallows infrastructure errors. Retrying a `QueryException` three times with
 * a backoff turns one clear database failure into a 500 with a wrong story
 * attached, and hides it for the length of the retry.
 *
 * The unremovable path travels on the exception, so a caller can report exactly
 * which file is left over without parsing the message.
 */
class MediaRemovalFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
