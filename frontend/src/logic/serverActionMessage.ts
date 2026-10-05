import type { I18n } from '@lingui/core';
import { t } from '@lingui/core/macro';

/**
 * What the UI shows after a server action that only ORDERS work.
 *
 * ## The rule, and why it exists
 *
 * Of the endpoints that reach THIS function, three answer a bare `{message}`
 * and none of them can know whether the mail left the building: since Position
 * 45 `MandantMailerService::send()` only dispatches `SendMandantMail`, so
 * `POST …/applications/{id}/resend`, `POST …/sub-applications/{id}/resend` and
 * `POST …/failed-mails/{id}/requeue` return as soon as the JOB is written. The
 * server therefore says "in die Warteschlange gestellt" — and `ApprovalsPage`
 * used to answer "E-Mail wurde erneut gesendet" from a string of its own, which
 * is a claim about a relay the process never talked to. `approvals.spec.ts`
 * pinned that claim; pinning it is part of the defect, not a guard against it.
 *
 * So: **the server's own words win.** A translated string of ours would be the
 * same lie in a different language.
 *
 * ## What that costs, named rather than hidden
 *
 * Nothing, since 2026-10-05. The backend messages used to be German only
 * (`AdminApplicationController:181/196`, `AdminSubApplicationController:192/207`,
 * `FailedMailController:99`), so an admin on the `en` locale read a German
 * success line. They now live in `backend/lang/{de,en}/mails.php` and the server
 * negotiates them from `Accept-Language`
 * (`backend/app/Http/Middleware/SetRequestLocale.php`), which the API client sets
 * to the locale the UI is currently showing (`logic/uiLocale.ts`). The server's
 * own words STILL win, in every language — `en` reads "E-mail was queued again.",
 * not a sentence invented here.
 *
 * ## Why the function is unchanged by that
 *
 * Because there is nothing to change. A localized string of our own would still
 * be a claim the endpoint never made, and translating the SERVER's claim is the
 * server's job. What was missing was the server's ability to say it in the
 * reader's language; that is now fixed at the source, so this module still does
 * the only thing it was written to do — show what the server said.
 *
 * The fallback branch below is the only case where this function invents text:
 * a 2xx whose body carried no `message` at all. It says what is true then and
 * nothing more — the job was ACCEPTED, nothing about the relay — and it is a
 * Lingui string, so it is already translated per active locale.
 */
export function serverActionMessage(serverMessage: string, i18n: I18n): string {
    const trimmed = serverMessage.trim();

    return trimmed !== '' ? trimmed : i18n._(t`Zustellauftrag angenommen.`);
}
