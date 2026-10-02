<?php

namespace App\Support;

use App\Jobs\SendMandantMail;
use Throwable;

/**
 * Reads the fields a dead-lettered `SendMandantMail` payload carries, WITHOUT
 * reviving the mail (and therefore without re-fetching its models).
 *
 * The queue stores a job as a JSON envelope whose `data.command` is a
 * PHP-serialized copy of the job object. Reconstructing the whole object would
 * unserialize the nested `Mailable`, and the application mailables use
 * `SerializesModels` — restoring them would hit the database for the applicant
 * and could fail for a row that has since been deleted. The only values the
 * dead-letter surface needs (`mandantId`, `mailableClass`, `recipient`) are
 * plain scalars on the JOB, so the unserialize is restricted to that one class
 * and nested objects stay `__PHP_Incomplete_Class` — never touched.
 *
 * Portable: nothing here touches the database. Used by the failed-job provider
 * (to fill `failed_jobs.mandant_id`) and by the DLQ API/resource, so both read
 * the payload through exactly one decoder.
 */
final class QueuedMailPayload
{
    /**
     * The `data.commandName` of a raw job payload, or null when it is not a
     * well-formed queued job.
     */
    public static function commandName(string $payload): ?string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        $commandName = $decoded['data']['commandName'] ?? null;

        return is_string($commandName) ? $commandName : null;
    }

    /**
     * The mail job carried by the payload, or null when the payload is not one
     * (another job, a malformed payload, a class that no longer exists).
     *
     * The unserialize is confined to `SendMandantMail`: every nested object
     * (notably the `Mailable`) becomes an incomplete class and is never read.
     */
    public static function mailJob(string $payload): ?SendMandantMail
    {
        if (self::commandName($payload) !== SendMandantMail::class) {
            return null;
        }

        $decoded = json_decode($payload, true);
        $command = is_array($decoded) ? ($decoded['data']['command'] ?? null) : null;

        if (! is_string($command)) {
            return null;
        }

        try {
            $job = @unserialize($command, ['allowed_classes' => [SendMandantMail::class]]);
        } catch (Throwable) {
            return null;
        }

        return $job instanceof SendMandantMail ? $job : null;
    }

    /**
     * The owning mandant of a dead-lettered mail job, or null when the payload
     * is not one. The failed-job provider writes this into `failed_jobs`.
     */
    public static function mandantId(string $payload): ?int
    {
        return self::mailJob($payload)?->mandantId;
    }
}
