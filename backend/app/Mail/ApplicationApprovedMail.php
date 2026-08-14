<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the applicant when his application is approved. Carries the public
 * verify link; the caller guarantees the `qr_token` exists (issued by
 * `QrTokenService::make` on approval) before constructing this mailable.
 */
class ApplicationApprovedMail extends AbstractApplicationMail
{
    public function __construct(
        public Application $application,
        public string $verifyUrl,
    ) {
        $this->prepare($application);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Dein Antrag wurde freigegeben',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.application-approved',
            with: [
                ...$this->viewData($this->application),
                'verifyUrl' => $this->verifyUrl,
            ],
        );
    }
}
