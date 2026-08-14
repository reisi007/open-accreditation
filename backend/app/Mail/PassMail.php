<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The "your pass is ready" mail (admin resend for an approved application).
 * Requires an approved application with an existing `qr_token`.
 */
class PassMail extends AbstractApplicationMail
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
            subject: 'Dein Akkreditierungs-Ausweis',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.pass',
            with: [
                ...$this->viewData($this->application),
                'verifyUrl' => $this->verifyUrl,
            ],
        );
    }
}
