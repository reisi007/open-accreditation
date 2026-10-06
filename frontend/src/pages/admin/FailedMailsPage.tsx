import { msg, t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { Modal } from '../../components/Modal';
import { WideTable } from '../../components/WideTable';
import { requeueFailedMail } from '../../api/client';
import type { FailedMail } from '../../api/types';
import { isSuperAdminUser } from '../../logic/adminRoles';
import { formatDateTime } from '../../logic/formatDate';
import { serverActionMessage } from '../../logic/serverActionMessage';
import { useAuth } from '../../logic/useAuth';
import { useFailedMails } from '../../logic/useFailedMails';
import { useMandants } from '../../logic/useMandants';
import {
    FAILED_MAIL_EXCEPTION_LIMIT,
    failedMailListErrorMessage,
    failedMailListState,
    failedMailWindow,
    filterFailedMails,
    hasMultiplePages,
    isTruncatedException,
    requeueFailedMailErrorMessage,
} from './failedMailUtils';

/**
 * The dead-letter queue for undelivered mandant mails (Position 45, Strom B).
 *
 * ## Why this page exists at all
 *
 * "No mail should be lost" is only a promise if a dead letter can be SEEN. A
 * letter that ran out of attempts sits in `failed_jobs` with no automatic way
 * back out — the only exit is a human, which without a surface is a shell
 * command (`queue:retry`) that people with deploy access know about and
 * everybody else does not. So this is the second half of the feature, not a
 * nicety on it.
 *
 * ## The three things this page is careful about
 *
 * 1. **It never claims more than the API delivered.** The list is PAGINATED
 *    (2026-10-06): the endpoint serves one window and reports that window in
 *    `meta`. So the page shows the server's `total` and the page counter
 *    together — "Seite 2 von 5 · 213 Briefe" — and the search result count
 *    always names the total it is a subset of ("2 Briefe von 213"). The old
 *    text ("the list is not paginated, the endpoint delivers all of them at
 *    once") was true of the endpoint as it was and would have become the exact
 *    opposite of what the page does now.
 * 2. **A cut exception is LABELLED as cut.** The server cuts it at 500
 *    characters; half a stack trace presented as the whole thing is the same
 *    defect class as the resend message this stream also fixed.
 * 3. **A refusal is an answer.** A foreign letter is a 404, a missing one is a
 *    404, and neither may render as an empty list or a silent nothing. Both
 *    surface next to the row they happened on.
 *
 * ## A page that stops existing
 *
 * The queue SHRINKS under the admin's feet: a requeue DELETES a letter, and a
 * colleague can requeue the tail between the moment page N is shown and the
 * moment "Weiter" is pressed. Requesting that page anyway answers an EMPTY
 * window — the queue would read as empty while it holds rows.
 *
 * Two derivations, and they answer different halves of it:
 *
 *  - `failedMailWindow()` clamps the HEADING immediately (page 5 of 4 becomes
 *    page 4 of 4), and the "next"/"back" bounds are built from it.
 *  - the render-time `setPage` below corrects the page STATE and re-requests, so
 *    the ROWS come back too.
 *
 * The split is not tidiness — clamping the heading alone would leave an empty
 * table under a correct one, and re-requesting alone would leave a wrong heading
 * for the frame in between. Neither half is enough on its own.
 *
 * ## A window the server cannot fill is not an empty queue
 *
 * The list area makes one of four claims (see `failedMailListState`), and the
 * three empty ones are three DIFFERENT claims. MEASURED 2026-10-06: 1050 letters,
 * `per_page=50` → `last_page: 21`, but `page=21` came back with **0 rows** (the
 * server's scan reads at most 20 windows). This page then said
 * "Alle Briefe wurden zugestellt." over a queue holding 1050 letters — the worst
 * lie this surface can tell, because an operator reads a full dead-letter queue
 * as a healthy one. The server now caps `last_page` at what it can serve, and the
 * `unreachable` state keeps the client honest about what it can see.
 *
 * ## What stays stale, named rather than glossed over
 *
 * Correcting the page lands the admin on a window SWR already has cached, and
 * that cached entry carries the `total` and `last_page` from BEFORE the shrink.
 * So the counter can read one shrink high until the next revalidation — an
 * OVER-count, never an under-count: the rows on screen are always real, and the
 * empty state is only reachable when a page genuinely has no rows. That is the
 * tolerable direction; the untolerable one ("213 Briefe" over a table with three
 * rows) cannot happen, because the count rendered is the server's, not
 * `rows.length`.
 */
export function FailedMailsPage() {
    const { i18n } = useLingui();
    const { user } = useAuth();
    const isSuperAdmin = isSuperAdminUser(user);

    // The mandant filter is only MEANINGFUL for a super admin; a mandant_admin
    // receives his mandant's letters and nothing else, so a filter over them
    // would be a control that cannot change anything. The mandant list is
    // itself super-admin-only, and the header switcher already reads it, so
    // this costs no extra request.
    const { mandants } = useMandants(isSuperAdmin);

    // The page lives here, and it is part of the SWR key — see `useFailedMails`.
    // A change of search or mandant filter goes back to page 1, because a filter
    // result on page 4 of an unfiltered list is a claim about a window the admin
    // cannot see.
    const [page, setPage] = useState(1);

    const { failedMails, meta, error, isLoading, revalidate } = useFailedMails(page);

    /**
     * Correct a page the server no longer has, DURING render.
     *
     * `setPage(effectivePage)` while rendering is React's documented
     * "adjusting state during render" pattern, and it is what makes this correct
     * rather than merely tidy: React discards the frame it is rendering and
     * immediately re-renders with the new value, so the empty window is never
     * painted. The two alternatives are both worse — an effect fires a frame
     * LATER (the empty table flashes), and clamping only the heading leaves a
     * correct "page 1 of 1" over an empty table, which is the same
     * "the queue is empty" lie one level down.
     *
     * The guards are what keep it finite: only when the server HAS answered, and
     * only when the two disagree. A server that keeps calling the page out of
     * range leaves `page` already at its clamp, so the condition goes false and
     * this is a no-op rather than a loop.
     */
    if (meta !== undefined && page > meta.last_page) {
        setPage(Math.max(1, meta.last_page));
    }

    const [search, setSearch] = useState('');
    const [mandantFilter, setMandantFilter] = useState('');
    const [confirmTarget, setConfirmTarget] = useState<FailedMail | null>(null);
    const [confirmError, setConfirmError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    /** Success of the LAST requeue — page-level, because the row is gone afterwards. */
    const [outcome, setOutcome] = useState<string | null>(null);
    /** Failure of the last requeue, kept per row so it stays next to its row. */
    const [rowError, setRowError] = useState<{ id: number; message: string } | null>(null);

    const mandantName = (mandantId: number | null): string => {
        if (mandantId === null) {
            return i18n._(t`unbekannter Verband`);
        }
        const match = (mandants ?? []).find((mandant) => mandant.id === mandantId);
        // A deleted mandant leaves its dead letters behind on purpose (no FK),
        // so "no name for this id" is a real state and gets an honest label
        // rather than a blank cell.
        return match === undefined ? `#${mandantId}` : match.name;
    };

    const all = failedMails ?? [];
    const visible = filterFailedMails(all, {
        search,
        mandantId: isSuperAdmin && mandantFilter !== '' ? Number(mandantFilter) : null,
    });
    const filtersActive = search.trim() !== '' || (isSuperAdmin && mandantFilter !== '');

    // The window the SERVER says this is, clamped so a requeue that shortened the
    // queue cannot leave a "page 5 of 5" heading over an empty table.
    const window = failedMailWindow(
        meta ?? { page: 1, per_page: all.length, total: all.length, last_page: 1 },
        all.length,
    );
    // Plain names because Lingui can only interpolate a simple variable, not
    // `window.page` (lingui/no-expression-in-message). Both are already clamped
    // by `failedMailWindow`.
    const currentPage = window.page;
    const lastPage = window.lastPage;

    // Which of the four claims this area makes — see `failedMailListState` for why
    // "no rows although the queue is not empty" is its own state and never reads
    // as "everything was delivered".
    const listState = failedMailListState({
        serverRows: all.length,
        visibleRows: visible.length,
        total: window.total,
        filtersActive,
    });

    const openConfirm = (entry: FailedMail) => {
        setConfirmTarget(entry);
        setConfirmError(null);
        setOutcome(null);
        setRowError(null);
    };

    const closeConfirm = () => {
        setConfirmTarget(null);
        setConfirmError(null);
    };

    const handleConfirm = async () => {
        if (confirmTarget === null) {
            return;
        }
        setSubmitting(true);
        setConfirmError(null);
        try {
            const message = await requeueFailedMail(confirmTarget.id);
            setOutcome(serverActionMessage(message, i18n));
            setRowError(null);
            setConfirmTarget(null);
            await revalidate();
        } catch (err) {
            // The dialog STAYS open with the reason: this is the one place where
            // swallowing the error would hide a decision the human just made.
            setConfirmError(requeueFailedMailErrorMessage(err, i18n));
            setRowError({ id: confirmTarget.id, message: requeueFailedMailErrorMessage(err, i18n) });
        } finally {
            setSubmitting(false);
        }
    };

    /**
     * A requeue removes a row, so on the LAST page the page after it may no
     * longer exist. `failedMailWindow()` already clamps what is DISPLAYED; this
     * only brings the request back to a page the server still has, so the reload
     * answers rows instead of an empty window.
     */
    const goToPage = (next: number) => {
        setPage(Math.min(Math.max(1, next), Math.max(1, window.lastPage)));
    };

    return (
        <section className="flex flex-col gap-6">
            <h1 className="text-3xl font-bold">{i18n._(t`Tote Briefe`)}</h1>
            <p className="text-base-content/70">
                {i18n._(
                    t`Briefe, die nach allen Zustellversuchen fehlgeschlagen sind. Sie bleiben hier, bis ein Mensch sie erneut einreiht — automatisch passiert das nie.`,
                )}
            </p>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="form-control">
                    <label className="label" htmlFor="failed-mail-search">
                        <span className="label-text">{i18n._(t`Suche`)}</span>
                    </label>
                    <input
                        id="failed-mail-search"
                        type="search"
                        className="input input-sm"
                        placeholder={i18n._(t`Empfänger, Mailable oder Fehlermeldung`)}
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            // Back to page 1: the new result set has no page 4, and
                            // staying on page 4 would render it as an empty queue.
                            setPage(1);
                        }}
                    />
                </div>
                {isSuperAdmin ? (
                    <div className="form-control">
                        <label className="label" htmlFor="failed-mail-mandant">
                            <span className="label-text">{i18n._(t`Mandant`)}</span>
                        </label>
                        <select
                            id="failed-mail-mandant"
                            className="select select-sm"
                            value={mandantFilter}
                            onChange={(event) => {
                                setMandantFilter(event.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">{i18n._(t`Alle Mandanten`)}</option>
                            {(mandants ?? []).map((mandant) => (
                                <option key={mandant.id} value={String(mandant.id)}>
                                    {mandant.name}
                                </option>
                            ))}
                        </select>
                    </div>
                ) : null}
            </div>

            {outcome ? (
                <div role="status" className="alert alert-success">
                    <span>{outcome}</span>
                </div>
            ) : null}

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{failedMailListErrorMessage(error, i18n)}</span>
                </div>
            ) : null}

            {failedMails !== undefined && !isLoading && error === undefined ? (
                <div className="flex flex-col gap-2">
                    {/* TWO numbers, always together. The filtered count alone would
                        read as a claim about the whole queue, and the search box only
                        ever sees the current page (see `filterFailedMails`) — so "2 von
                        213" is the honest sentence and "2 Briefe" is not. */}
                    <p aria-live="polite">
                        {i18n._({
                            ...msg`{count, plural, one {# Brief} other {# Briefe}}`,
                            values: { count: visible.length },
                        })}
                        {' · '}
                        {i18n._({
                            ...msg`{total, plural, one {# Brief} other {# Briefe}} insgesamt`,
                            values: { total: window.total },
                        })}
                    </p>
                    {/*
                      The page counter names the WINDOW this view is, so a page that
                      holds 50 of 213 letters is never read as the whole queue. It is
                      the counterpart to the count above and is deliberately shown even
                      on a single page — `total` is the claim that matters there.
                    */}
                    {hasMultiplePages(window.lastPage) ? (
                        <div className="join">
                            <button
                                type="button"
                                className="btn btn-sm join-item"
                                disabled={window.page <= 1 || isLoading}
                                onClick={() => goToPage(window.page - 1)}
                            >
                                {i18n._(t`Zurück`)}
                            </button>
                            {/* The page numbers go in as VALUES, not as a member expression: Lingui
                        can only place a simple variable in the message, and
                        `window.page` is not one (lingui/no-expression-in-message). */}
                            <span
                                className="btn btn-sm join-item btn-ghost pointer-events-none"
                                aria-live="polite"
                            >
                                {i18n._(
                                    t`Seite ${currentPage} von ${lastPage}`,
                                )}
                            </span>
                            <button
                                type="button"
                                className="btn btn-sm join-item"
                                disabled={window.page >= window.lastPage || isLoading}
                                onClick={() => goToPage(window.page + 1)}
                            >
                                {i18n._(t`Weiter`)}
                            </button>
                        </div>
                    ) : null}

                    {listState !== 'filled' ? (
                        <div className="card border border-base-300 bg-base-100">
                            <div className="card-body items-center justify-center py-16 text-center">
                                <span className="iconify mdi--email-off-outline text-6xl text-base-content/40"></span>
                                {/* Three different claims, three different sentences. The
                                    unreachable one is the load-bearing one: it used to fall
                                    into the "everything was delivered" branch and describe a
                                    queue that holds a thousand letters as a healthy one. */}
                                {listState === 'unreachable' ? (
                                    <>
                                        <h2 className="card-title">
                                            {i18n._(t`Diese Seite ist nicht erreichbar.`)}
                                        </h2>
                                        <p className="text-base-content/70">
                                            {i18n._(
                                                t`Der Server liefert für diese Seite keine Briefe, obwohl die Warteschlange nicht leer ist. Gehe zurück auf Seite 1.`,
                                            )}
                                        </p>
                                        <div className="mt-2">
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-primary"
                                                onClick={() => setPage(1)}
                                            >
                                                {i18n._(t`Zurück auf Seite 1`)}
                                            </button>
                                        </div>
                                    </>
                                ) : listState === 'filtered-empty' ? (
                                    <>
                                        <h2 className="card-title">{i18n._(t`Keine Briefe für diese Filter.`)}</h2>
                                        <p className="text-base-content/70">
                                            {i18n._(t`Passe die Filter an, um Briefe anzuzeigen.`)}
                                        </p>
                                    </>
                                ) : (
                                    <>
                                        {/* A QUEUE that is empty is the good state, and it is
                                            its own state — not "nothing found", which is what
                                            an error looks like. */}
                                        <h2 className="card-title">{i18n._(t`Keine toten Briefe.`)}</h2>
                                        <p className="text-base-content/70">
                                            {i18n._(
                                                t`Alle Briefe wurden zugestellt. Sobald ein Brief alle Zustellversuche verbraucht, erscheint er hier.`,
                                            )}
                                        </p>
                                    </>
                                )}
                            </div>
                        </div>
                    ) : (
                        <WideTable>
                            <div className="overflow-x-auto">
                                <table className="table">
                                    <thead>
                                        <tr>
                                            <th className="sticky top-0 z-10 bg-base-100">
                                                {i18n._(t`Zeitpunkt`)}
                                            </th>
                                            {isSuperAdmin ? (
                                                <th className="sticky top-0 z-10 bg-base-100">
                                                    {i18n._(t`Mandant`)}
                                                </th>
                                            ) : null}
                                            <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Empfänger`)}</th>
                                            <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Mailable`)}</th>
                                            <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Fehler`)}</th>
                                            <th className="sticky top-0 z-10 bg-base-100 w-40">
                                                {i18n._(t`Aktionen`)}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {visible.map((entry) => (
                                            <tr key={entry.id}>
                                                <td className="whitespace-nowrap py-3">
                                                    {entry.failed_at === null ? (
                                                        '—'
                                                    ) : (
                                                        formatDateTime(entry.failed_at, i18n.locale ?? undefined)
                                                    )}
                                                </td>
                                                {isSuperAdmin ? (
                                                    <td className="py-3">
                                                        <span className="text-sm">
                                                            {mandantName(entry.mandant_id)}
                                                        </span>
                                                    </td>
                                                ) : null}
                                                <td className="min-w-0 max-w-48 py-3">
                                                    <div className="truncate" title={entry.recipient ?? ''}>
                                                        {entry.recipient ?? '—'}
                                                    </div>
                                                </td>
                                                <td className="min-w-0 py-3">
                                                    <div className="flex flex-col gap-1">
                                                        <div
                                                            className="truncate text-sm"
                                                            title={entry.mailable ?? ''}
                                                        >
                                                            {entry.mailable ?? '—'}
                                                        </div>
                                                        <span className="badge badge-ghost badge-sm">
                                                            <span className="truncate">
                                                                {i18n._(t`Queue`)}: {entry.queue}
                                                            </span>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="min-w-0 py-3">
                                                    <div className="flex flex-col gap-1">
                                                        <div
                                                            className="max-w-72 truncate font-mono text-xs"
                                                            title={entry.exception}
                                                        >
                                                            {entry.exception}
                                                        </div>
                                                        {isTruncatedException(entry.exception) ? (
                                                            <span className="badge badge-warning badge-sm">
                                                                {i18n._({
                                                                    ...msg`gekürzt auf {limit} Zeichen`,
                                                                    values: { limit: FAILED_MAIL_EXCEPTION_LIMIT },
                                                                })}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="py-3">
                                                    {rowError !== null && rowError.id === entry.id ? (
                                                        <p role="alert" className="mb-2 max-w-56 text-sm text-error">
                                                            {rowError.message}
                                                        </p>
                                                    ) : null}
                                                    <div className="flex justify-end">
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline"
                                                            onClick={() => openConfirm(entry)}
                                                        >
                                                            {i18n._(t`Erneut einreihen`)}
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </WideTable>
                    )}
                </div>
            ) : null}

            {confirmTarget ? (
                <Modal onClose={closeConfirm}>
                    <h3 className="text-lg font-bold">{i18n._(t`Brief erneut einreihen`)}</h3>
                    <div className="mt-4 flex flex-col gap-3">
                        <p className="text-sm text-base-content/70">
                            {i18n._(t`Empfänger`)}: {confirmTarget.recipient ?? '—'}
                        </p>
                        {confirmError ? (
                            <div role="alert" className="alert alert-error">
                                <span>{confirmError}</span>
                            </div>
                        ) : null}
                        <p className="text-sm">
                            {i18n._(
                                t`Der Brief geht zurück in die Warteschlange und bekommt ein neues Zustellbudget. Ob die Zustellung gelingt, entscheidet erst der Worker.`,
                            )}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                className="btn btn-primary"
                                disabled={submitting}
                                onClick={() => void handleConfirm()}
                            >
                                {submitting ? (
                                    <span className="loading loading-spinner loading-xs"></span>
                                ) : null}
                                {i18n._(t`Erneut einreihen`)}
                            </button>
                            <button type="button" className="btn" disabled={submitting} onClick={closeConfirm}>
                                {i18n._(t`Abbrechen`)}
                            </button>
                        </div>
                    </div>
                </Modal>
            ) : null}
        </section>
    );
}
