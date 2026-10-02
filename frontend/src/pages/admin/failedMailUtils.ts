import type { I18n } from '@lingui/core';
import { t } from '@lingui/core/macro';
import { ApiError } from '../../api/client';
import type { FailedMail } from '../../api/types';

/**
 * The width `FailedMailResource` cuts the exception at:
 * `'exception' => Str::limit($this->exception, 500)`
 * (`backend/app/Http/Resources/FailedMailResource.php:40`).
 *
 * Duplicated here on purpose — and only here. A second copy inside the page
 * would be a second number that could drift from the server's, and this one
 * exists precisely so the "gekürzt" marker means the server's 500.
 */
export const FAILED_MAIL_EXCEPTION_LIMIT = 500;

/** `Str::limit`'s default end marker (`Illuminate\Support\Str::limit(…, $end = '...')`). */
const LIMIT_END_MARKER = '...';

/**
 * Did the SERVER cut this exception?
 *
 * ## What is measured, and what is inferred
 *
 * `Str::limit()` runs `mb_strimwidth($value, 0, 500, '...')`, which returns the
 * value unchanged when it already fits and otherwise fills the 500 width and
 * appends `...`. So the marker is present **iff** the server cut the text — with
 * exactly one exception: a trace that ends in `...` on its own.
 *
 * ## Why the length is NOT part of the test
 *
 * The obvious tightening ("marker AND near 500 characters") was rejected after
 * measuring what it would break: `mb_strimwidth` counts DISPLAY WIDTH, so a cut
 * CJK trace is 250 `String.length` units wide and would slip past the check. The
 * remaining false direction is a trace that genuinely ends in `...` being
 * labelled as cut.
 *
 * ## Which of the two mistakes this can make
 *
 *   - false NEGATIVE: a cut trace shown as if it were whole. That is the one the
 *     board forbids — an operator reading half a stack trace as the whole thing.
 *   - false POSITIVE: a whole trace (≤ 500 characters anyway) carrying a
 *     "gekürzt" badge.
 *
 * The label is a badge next to text that is fully readable in the row's `title`,
 * so the false positive costs a wrong hint and the false negative would cost the
 * operator the diagnosis. The cheap error is the chosen one.
 */
export function isTruncatedException(exception = ''): boolean {
    return exception.endsWith(LIMIT_END_MARKER);
}

export interface FailedMailFilter {
    /** Free text over recipient, mailable, queue and exception. */
    search: string;
    /** `null` = every mandant (and is what a `mandant_admin` always gets). */
    mandantId: number | null;
}

/**
 * The client-side filter of the dead-letter list.
 *
 * Client-side because the endpoint cannot filter: `FailedMailController::index()`
 * takes no parameters at all. That is also why the page says out loud that the
 * list is not paginated — a filter that runs on everything the server sent is
 * only as complete as the server's answer (`features/mail-delivery.md §8`).
 */
export function filterFailedMails(failedMails: FailedMail[], filter: FailedMailFilter): FailedMail[] {
    const needle = filter.search.trim().toLowerCase();

    return failedMails.filter((entry) => {
        if (filter.mandantId !== null && entry.mandant_id !== filter.mandantId) {
            return false;
        }
        if (needle === '') {
            return true;
        }

        return [entry.recipient ?? '', entry.mailable ?? '', entry.queue, entry.exception].some((haystack) =>
            haystack.toLowerCase().includes(needle),
        );
    });
}

/**
 * Localize a failure of `POST /api/admin/failed-mails/{id}/requeue`.
 *
 * ## The 404 is the interesting one, and it must name both cases
 *
 * A foreign letter is **404, not 403** (`FailedMailController::requeue:77-82` —
 * the same shape as the tenant CRUD), and so is a letter that is simply gone. The
 * message therefore says "existiert nicht (mehr) ODER gehört zu einem anderen
 * Verband" instead of guessing: an admin who just lost the mandant switcher must
 * not read the answer as "the queue is empty", which is exactly the failure mode
 * the stream exists to prevent. The mandant scope is enforced by the BACKEND —
 * this text only makes an answer the API really gave legible.
 */
export function requeueFailedMailErrorMessage(err: unknown, i18n: I18n): string {
    if (err instanceof ApiError) {
        if (err.status === 404) {
            return i18n._(
                t`Dieser Brief existiert nicht (mehr) oder gehört zu einem anderen Verband. Für einen fremden Brief antwortet die API mit 404.`,
            );
        }
        if (err.status === 403) {
            return i18n._(t`Keine Berechtigung, tote Briefe erneut einzureihen.`);
        }
        if (err.status === 429) {
            return i18n._(t`Zu viele Versuche. Bitte kurz warten und es erneut versuchen.`);
        }
        if (err.message !== '') {
            return err.message;
        }
    }

    return i18n._(t`Der Brief konnte nicht erneut eingereiht werden.`);
}

/** Load failure of the LIST — kept apart from the requeue text on purpose. */
export function failedMailListErrorMessage(err: unknown, i18n: I18n): string {
    if (err instanceof ApiError) {
        if (err.status === 403) {
            return i18n._(t`Keine Berechtigung für die Liste der toten Briefe.`);
        }
        if (err.status === 404) {
            return i18n._(t`Für diesen Mandanten ist die Liste der toten Briefe nicht erreichbar.`);
        }
        if (err.message !== '') {
            return err.message;
        }
    }

    return i18n._(t`Tote Briefe konnten nicht geladen werden.`);
}
