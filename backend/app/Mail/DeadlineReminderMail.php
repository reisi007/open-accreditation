<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to applicants with a `requested` application whose accreditation
 * deadline ends within the reminder window (the `reminders:send` command).
 * Deadline is rendered in German `DD.MM.YYYY` format.
 */
class DeadlineReminderMail extends AbstractApplicationMail
{
    public string $deadlineLabel;

    public function __construct(
        public Application $application,
    ) {
        $this->deadlineLabel = $application->accreditation->deadline_end?->format('d.m.Y') ?? '';

        $this->prepare($application);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Frist läuft bald ab',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.deadline-reminder',
            with: [
                ...$this->viewData($this->application),
                'deadline' => $this->deadlineLabel,
            ],
        );
    }
}
