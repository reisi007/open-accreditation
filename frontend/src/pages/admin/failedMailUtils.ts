import type { I18n } from '@lingui/core';
import { t } from '@lingui/core/macro';
import { ApiError } from '../../api/client';
import type { FailedMail, PageMeta } from '../../api/types';

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

/**
 * The window a page control needs, derived from what the server reported.
 *
 * ## Every field here is `min`/`max`-clamped on purpose
 *
 * The server's `total` is a documented UPPER BOUND (see `PageMeta`), and the
 * page after the last one is genuinely empty. Both mean the raw numbers can ask
 * for something no UI should render:
 *
 *  - `page` above `last_page` (a stale button, or a colleague's requeue that
 *    shortened the queue) — clamped down, so "next" from the last page is a
 *    no-op instead of an off-range request;
 *  - `last_page` below 1 — the server already guarantees ≥ 1, and the clamp
 *    keeps that guarantee local to the arithmetic that depends on it;
 *  - `total` below the rows actually on screen — `total` counts the SQL scope
 *    and can under-read nothing, but the floor stops a malformed response from
 *    rendering "0 Briefe" above a table with rows in it.
 */
export interface FailedMailWindow {
    /** The 1-based page to show, never above `lastPage`. */
    page: number;
    lastPage: number;
    /** The server's count for the whole queue — an upper bound, see `PageMeta`. */
    total: number;
}

export function failedMailWindow(meta: PageMeta, rowsOnPage: number): FailedMailWindow {
    const lastPage = Math.max(1, meta.last_page);

    return {
        page: Math.min(Math.max(1, meta.page), lastPage),
        lastPage,
        total: Math.max(meta.total, rowsOnPage),
    };
}

/**
 * Should the page control be rendered at all?
 *
 * False for a single page, because a lone "Seite 1 von 1" with two dead buttons
 * is furniture that says nothing.
 */
export function hasMultiplePages(lastPage: number): boolean {
    return lastPage > 1;
}

/**
 * The four states the list area can be in — the empty ones are three DIFFERENT
 * claims, not one.
 *
 * ## Why "unreachable" is its own state and not an empty queue
 *
 * MEASURED 2026-10-06 (verification round 71): 1050 letters, `per_page=50` →
 * `meta.last_page: 21`, but `page=21` answered with **0 rows**, because the
 * server's scan reads at most 20 windows. The page then rendered
 * `<h2>Keine toten Briefe.</h2>` with the sentence "Alle Briefe wurden
 * zugestellt." — over a queue holding 1050 letters. That is the worst lie this
 * surface can tell: an operator reads a full dead-letter queue as a healthy one.
 *
 * The server now caps `meta.last_page` at what it can serve
 * (`FailedMailController::reachableLastPage()`), so that exact case cannot arise
 * any more. The state is kept anyway, because `rows === 0 && total > 0` also
 * happens when a page's window is filled with non-mail rows (`total` is a
 * documented upper bound), and a client that trusts its own emptiness here has
 * nothing left to catch it.
 *
 * The order is the argument, so it is stated rather than left to be re-derived:
 *
 *  1. rows on screen → `filled`, whatever the server says about the rest;
 *  2. rows arrived and the filter removed them → `filtered-empty`, because a
 *     filter CAN empty a window — it just cannot make one unreachable;
 *  3. no rows at all although `total > 0` → `unreachable`;
 *  4. no rows and nothing in the queue → `empty-queue`, the good news.
 */
export type FailedMailListState = 'filled' | 'unreachable' | 'filtered-empty' | 'empty-queue';

export interface FailedMailListStateInput {
    /** Rows the server sent for THIS page, before the client filter. */
    serverRows: number;
    /** Rows left after the client filter. */
    visibleRows: number;
    /** The server's queue count (an upper bound, see `PageMeta`). */
    total: number;
    /** Whether search or the mandant filter is set. */
    filtersActive: boolean;
}

export function failedMailListState(input: FailedMailListStateInput): FailedMailListState {
    if (input.visibleRows > 0) {
        return 'filled';
    }
    if (input.serverRows > 0) {
        return 'filtered-empty';
    }
    if (input.total > 0) {
        return 'unreachable';
    }
    if (input.filtersActive) {
        return 'filtered-empty';
    }
    return 'empty-queue';
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
 * ## It filters a PAGE, and the UI must say so
 *
 * Client-side because the endpoint takes no filter parameter. Since the list is
 * paginated (`features/mail-delivery.md §8`) that means a filter can only ever
 * see the rows of the CURRENT page — so a search that matches a letter three
 * pages back reports "nothing found" on this page. That is a limitation, not a
 * bug to be smoothed over, and the reason the page renders the SERVER's total
 * next to the filtered count: "2 Briefe von 213" is honest, "2 Briefe" reads as
 * a claim about the whole queue.
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
