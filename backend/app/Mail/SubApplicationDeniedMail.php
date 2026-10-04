<?php

namespace App\Mail;

use App\Models\SubApplication;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the applicant when his Park-/Sitzkarte (sub-accreditation, D9) is
 * denied — the counterpart of {@see SubApplicationApprovedMail}.
 *
 * The denial reason is mandatory and is printed verbatim: the call sites only
 * build this mailable for a `denied` row, and every writer of such a row
 * persists a reason (`denySubApplication()` refuses an empty one, the bulk plan
 * writes `AllocationRules::REASON_QUOTA` / `REASON_BLACKLIST`). That is also why
 * the `REASON_PARENT_REVOKED` cascade of a revoked main accreditation reads
 * correctly here without a word of extra wording.
 *
 * No wallet pass: a pass is only ever attached to an approval.
 */
class SubApplicationDeniedMail extends AbstractSubApplicationMail
{
    public function __construct(
        public SubApplication $subApplication,
        public string $reason,
    ) {
        $this->prepare($subApplication);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Deine '.$this->typeLabel($this->subApplication).' wurde abgelehnt',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sub-application-denied',
            with: [
                ...$this->viewData($this->subApplication),
                'reason' => $this->reason,
            ],
        );
    }
}
