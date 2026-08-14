<?php

namespace App\Mail;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Shared plumbing for the P5 applicant notifications (approval, denial,
 * deadline reminder, pass). Every mailable targets the applicant of an
 * `Application`:
 *
 * - the recipient is derived from `application.user` (set via `to()`),
 * - the accreditation context (category/event/team/mandant domain) is eager-
 *   loaded once so the views and the verify-link builder never trigger
 *   unexpected lazy loads,
 * - the view data (user/category/event/team labels) is built in one place.
 *
 * German only for now (like `ActivationMail`); i18n of the mail templates is
 * a documented follow-up.
 */
abstract class AbstractApplicationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Load the accreditation context, bind the applicant as recipient and
     * return the application (fully prepared for the view).
     */
    protected function prepare(Application $application): Application
    {
        $application->loadMissing([
            'user:id,email,name',
            'accreditation.category',
            'accreditation.event',
            'accreditation.team',
            'accreditation.mandant.domains',
        ]);

        if ($application->user !== null && $application->user->email !== null) {
            $this->to($application->user->email, $application->user->name);
        }

        return $application;
    }

    /**
     * The shared view data of every applicant notification.
     *
     * @return array<string, string|null>
     */
    protected function viewData(Application $application): array
    {
        return [
            'userName' => $application->user?->name ?? '',
            'categoryName' => $application->accreditation?->category?->name ?? '',
            'eventTitle' => $application->accreditation?->event?->title,
            'teamName' => $application->accreditation?->team?->name,
        ];
    }
}
