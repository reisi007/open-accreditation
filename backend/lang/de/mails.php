<?php

/**
 * DE source strings for the `{message}` bodies the admin mail surfaces answer.
 *
 * ## Why this file exists
 *
 * `POST …/applications/{id}/resend`, `POST …/sub-applications/{id}/resend` and
 * `POST …/failed-mails/{id}/requeue` each answered a hardcoded GERMAN string.
 * `frontend/src/logic/serverActionMessage.ts` shows the server's own words
 * (deliberately — the endpoint only ORDERS a delivery job, so a string of ours
 * would claim a relay the process never talked to), which meant an admin on the
 * `en` locale read a German success line. That was a localization gap in the
 * BACKEND, reported in `features/mail-delivery.md` §8.
 *
 * ## The claim this file must NOT strengthen
 *
 * The wording stays a QUEUEING claim. `MandantMailerService::send()` only
 * dispatches `SendMandantMail`, so no controller here knows whether the relay
 * answered. `MailTest`/`QueuedMailTest` and the E2E specs pin the absence of
 * "erneut gesendet" — translating the string must not smuggle that claim back
 * in under a different language, so the EN catalog says "queued", never "sent".
 *
 * ## Keys are the shared wording, not the callers' names
 *
 * All three surfaces answer the identical sentence, because they have the
 * identical contract (a delivery job was written, nothing more). One key rather
 * than three keeps that identity structural: a future divergence is a decision,
 * not an accident of copy-paste.
 *
 * The 422 bodies live here too. They were English literals in a German file,
 * and `resendMailUtils` maps 422/403 to a localized sentence of its own — the
 * server text is what a non-SPA client (curl, a mobile app) reads, so it is a
 * user-facing string like any other.
 *
 * ## Which locale answers
 *
 * `App\Http\Middleware\SetRequestLocale` negotiates from the request's
 * `Accept-Language` and falls back to German. Both facts — including the
 * "both catalogs define the same keys" parity — are pinned by
 * `Tests\Feature\ServerMessageLocaleTest`, named rather than repeated here so
 * this file cannot drift into describing a gate that moved.
 */
return [
    'queued' => 'E-Mail wurde erneut in die Warteschlange gestellt.',

    'no_mailable_reason' => 'Für diesen Antrag gibt es keinen versendbaren Grund.',
    'no_mailable_status' => 'Für diesen Antrag gibt es keinen versendbaren Status.',

    'sub_no_mailable_reason' => 'Für diesen Sub-Antrag gibt es keinen versendbaren Grund.',
    'sub_no_mailable_status' => 'Für diesen Sub-Antrag gibt es keinen versendbaren Status.',
];
