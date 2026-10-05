import type { I18n } from '@lingui/core';
import { t } from '@lingui/core/macro';
import { ApiError } from '../../api/client';

/**
 * Which kind of row the admin clicked "resend" on.
 *
 * Both resend endpoints reject for the same reasons (422 no mailable
 * status/reason, 403 foreign team scope, 404 foreign mandant) but name a
 * DIFFERENT row in the UI: the main applications table says "Antrag", the
 * sub-applications table says "Sub-Antrag". The mapped sentences therefore
 * carry the subject, instead of the sub-row borrowing the main row's wording —
 * which would be true only by accident, and only until someone reads the table
 * header.
 */
export type ResendSubject = 'application' | 'subApplication';

/**
 * Localize an error raised by
 * `POST /api/admin/applications/{id}/resend` or its sub-application counterpart.
 *
 * Neither of those bodies is echoed any more, and what they carry is worth
 * stating precisely — the comment that stood here for a long time claimed both
 * were English, and neither has been since the catalogs landed:
 *
 *  - **422** answers a CATALOG string — `__('mails.*')`, so German by default and
 *    English wherever `Accept-Language` negotiates `en`
 *    (`AdminApplicationController.php:193,204`,
 *    `AdminSubApplicationController.php:208,219`; catalogs
 *    `backend/lang/{de,en}/mails.php`). Both used to be English literals.
 *  - **403** carries NO message at all: `abort_unless($query->exists(), 403)`
 *    without a message (`AdminApplicationController.php:218`,
 *    `AdminSubApplicationController.php:234`), which the exception handler
 *    renders as `{"message": ""}` (measured, 2026-10-05).
 *
 * Both are mapped to a sentence of our own below, so neither wording is
 * something this module has to track. Field errors (unexpected on these
 * endpoints) and other ApiError messages keep the existing ApiError handling —
 * including the **404** of a foreign mandant, whose body ("No query results for
 * model […]") is the server's own words and is more truthful than any sentence
 * of ours. Network failures and unknown errors fall back to a generic localized
 * message.
 *
 * `subject` defaults to `'application'`, which is the main-request wording; a
 * sub-row passes `'subApplication'` explicitly.
 */
export function resendMailErrorMessage(err: unknown, i18n: I18n, subject: ResendSubject = 'application'): string {
    // Built inside the function on purpose: the `t` macro must never run at
    // module scope (blank shell chunk in the production bundle — see
    // `frontend/AGENTS.md`).
    const sentences =
        subject === 'subApplication'
            ? {
                  unmailable: t`Für diesen Sub-Antrag kann keine E-Mail gesendet werden.`,
                  forbidden: t`Keine Berechtigung für diesen Sub-Antrag.`,
              }
            : {
                  unmailable: t`Für diesen Antrag kann keine E-Mail gesendet werden.`,
                  forbidden: t`Keine Berechtigung für diesen Antrag.`,
              };

    if (err instanceof ApiError) {
        const first = Object.values(err.info.errors ?? {})
            .flat()
            .find((entry): entry is string => typeof entry === 'string' && entry !== '');
        if (first !== undefined) {
            return first;
        }
        if (err.status === 422) {
            return i18n._(sentences.unmailable);
        }
        if (err.status === 403) {
            return i18n._(sentences.forbidden);
        }
        if (err.message !== '') {
            return err.message;
        }
    }

    return i18n._(t`E-Mail konnte nicht gesendet werden.`);
}
