<?php

namespace App\Exceptions;

use App\Jobs\SendMandantMail;
use RuntimeException;

/**
 * Position 45 (2026-10-02): a mail delivery refused because an earlier attempt
 * of the SAME `deliveryId` already holds the idempotency claim.
 *
 * ## Why this is an exception and not a `return`
 *
 * The claim exists to stop the one duplicate-delivery shape a queue introduces
 * (worker sent the mail, died before the ack, job re-runs after `retry_after`).
 * The obvious way to honour it is to `return` — and that is exactly the silent
 * loss it used to cause: the job returns normally, so the worker **deletes** it,
 * and the result is no mail, no `failed_jobs` row and no log line. "No mail
 * should be lost" forbids that.
 *
 * Throwing instead makes the refusal *visible*: the job is retried under its
 * existing cap (`$tries = 5`), and if the claim is still held after the last
 * attempt it lands in `failed_jobs` with this message — where a `mandant_admin`
 * or `super_admin` can see it and requeue it.
 *
 * ## How it is resolved
 *
 * A manual requeue mints a FRESH `deliveryId`
 * (`FailedMailController::prepareForRequeue()`), which is a new claim key and
 * therefore a genuinely new, unblocked delivery. A human saying "send it again"
 * is the escape hatch; the machine refusing a duplicate is the guard.
 *
 * @see SendMandantMail::handle()
 */
class MailDeliveryAlreadyClaimedException extends RuntimeException
{
    public static function forDelivery(string $deliveryId, int $claimTtlSeconds): self
    {
        return new self(sprintf(
            'Mail delivery %s was already claimed within the last %d seconds '
            .'(crash-before-ack window); refusing a duplicate send. '
            .'Requeue the dead letter to deliver it again.',
            $deliveryId,
            $claimTtlSeconds,
        ));
    }
}
