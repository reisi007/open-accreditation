<?php

namespace App\Mail;

use App\Models\SubApplication;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the applicant when his Park-/Sitzkarte (sub-accreditation, D9) is
 * approved.
 *
 * The P6 reason this mailable exists at all: a sub-approval had no mail at all,
 * so the sub-pass could not ride along with one. The Apple `.pkpass` and the
 * Google payload therefore attach here exactly as they do on
 * {@see ApplicationApprovedMail} — see
 * {@see AbstractSubApplicationMail::buildWalletAttachments()} for the fail-safe
 * and for why only `approved` rows carry a pass.
 *
 * No verify URL is passed in: a sub row carries no `qr_token`, and the
 * constructor resolves everything else from the sub-application itself (see
 * {@see AbstractSubApplicationMail::verifyUrl()}).
 */
class SubApplicationApprovedMail extends AbstractSubApplicationMail
{
    public function __construct(
        public SubApplication $subApplication,
    ) {
        $this->prepare($subApplication);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Deine '.$this->typeLabel($this->subApplication).' wurde freigegeben',
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return $this->buildWalletAttachments($this->subApplication);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.sub-application-approved',
            with: [
                ...$this->viewData($this->subApplication),
                'verifyUrl' => $this->verifyUrl($this->subApplication),
            ],
        );
    }
}
