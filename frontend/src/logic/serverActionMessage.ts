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
 * The backend messages are German only (`AdminApplicationController:181/196`,
 * `AdminSubApplicationController:192/207`, `FailedMailController:99`), so an
 * admin on the `en` locale reads a German success line. That is a localization
 * gap in the BACKEND — `message` is not a translatable resource there — and it
 * is reported rather than papered over: the alternative (a localized string of
 * our own) is the claim this module exists to delete.
 *
 * The fallback branch below is the only case where this function invents text:
 * a 2xx whose body carried no `message` at all. It says what is true then and
 * nothing more — the job was ACCEPTED, nothing about the relay.
 */
export function serverActionMessage(serverMessage: string, i18n: I18n): string {
    const trimmed = serverMessage.trim();

    return trimmed !== '' ? trimmed : i18n._(t`Zustellauftrag angenommen.`);
}
