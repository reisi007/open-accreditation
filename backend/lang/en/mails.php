<?php

/**
 * EN catalog for `mails.php` — see the DE file for why the group exists.
 *
 * ## "queued", never "sent"
 *
 * The German source says "in die Warteschlange gestellt" (put into the queue),
 * and that is the whole truthfulness budget of this string: `send()` dispatches
 * a job and returns. The English wording therefore says **queued** as well — a
 * translation that reached for "has been sent again" would be the very claim
 * `serverActionMessage.ts` was written to delete, and it would do so in the one
 * language nobody on this project reads by default.
 * `Tests\Feature\ServerMessageLocaleTest::test_no_locale_claims_the_mail_was_sent`
 * asserts this per locale, so improving the wording into a delivery claim goes
 * red instead of shipping.
 *
 * The 422 sentences are rendered into the UI as `resendMailUtils`' own
 * localized text, so they exist here for non-SPA clients; they name the ROW
 * ("application" vs "sub-application") because the two endpoints reject for the
 * same reasons on different rows.
 */
return [
    'queued' => 'E-mail was queued again.',

    'no_mailable_reason' => 'This application has no mailable reason.',
    'no_mailable_status' => 'This application has no mailable status.',

    'sub_no_mailable_reason' => 'This sub-application has no mailable reason.',
    'sub_no_mailable_status' => 'This sub-application has no mailable status.',
];
