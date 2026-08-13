<?php

namespace App\Enums;

/**
 * Media types a user can upload for their accreditation application.
 * `portrait` and `press_id` are singular (one per user+type, replacing the
 * previous file); `attachment` allows multiple files.
 */
enum MediaType: string
{
    case PORTRAIT = 'portrait';
    case PRESS_ID = 'press_id';
    case ATTACHMENT = 'attachment';

    /**
     * Whether only one file may exist per user + type (new upload replaces it).
     */
    public function isSingular(): bool
    {
        return $this !== self::ATTACHMENT;
    }
}
