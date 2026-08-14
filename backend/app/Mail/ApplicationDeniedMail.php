<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the applicant when his application is denied/revoked. The denial
 * reason is mandatory — the dispatch sites only build this mailable when
 * `application.reason` is a non-empty string.
 */
class ApplicationDeniedMail extends AbstractApplicationMail
{
    public function __construct(
        public Application $application,
        public string $reason,
    ) {
        $this->prepare($application);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Dein Antrag wurde abgelehnt',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.application-denied',
            with: [
                ...$this->viewData($this->application),
                'reason' => $this->reason,
            ],
        );
    }
}
