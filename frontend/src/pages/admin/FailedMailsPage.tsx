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
    filterFailedMails,
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
 * ## The two things this page is careful about
 *
 * 1. **It never claims more than the API delivered.** The list endpoint is not
 *    paginated (`FailedMailController::index()` reads the whole table), so the
 *    page says so instead of letting a long list read as "everything there is".
 *    The exception text is cut server-side at 500 characters, and a cut trace is
 *    LABELLED as cut — half a stack trace presented as the whole thing is the
 *    same defect class as the resend message this stream also fixed.
 * 2. **A refusal is an answer.** A foreign letter is a 404, a missing one is a
 *    404, and neither may render as an empty list or a silent nothing. Both
 *    surface next to the row they happened on.
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
    const { failedMails, error, isLoading, revalidate } = useFailedMails();

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
                        onChange={(event) => setSearch(event.target.value)}
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
                            onChange={(event) => setMandantFilter(event.target.value)}
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
                    <p aria-live="polite">
                        {i18n._({
                            ...msg`{count, plural, one {# Brief} other {# Briefe}}`,
                            values: { count: visible.length },
                        })}
                    </p>
                    {/*
                      The list endpoint is not paginated (`FailedMailController::index()`
                      does `->get()` over the whole table and filters in PHP). Saying so
                      here is what keeps a long list from reading as "everything there is";
                      it is a statement about the current state of the API, not a promise
                      that pagination will never come — `features/mail-delivery.md §8`
                      carries it as an open point.
                    */}
                    <p className="text-sm text-base-content/60">
                        {i18n._({
                            ...msg`Die Liste wird nicht seitenweise geladen: der Endpunkt liefert alle {count, plural, one {# Brief} other {# Briefe}} auf einmal.`,
                            values: { count: all.length },
                        })}
                    </p>

                    {visible.length === 0 ? (
                        <div className="card border border-base-300 bg-base-100">
                            <div className="card-body items-center justify-center py-16 text-center">
                                <span className="iconify mdi--email-off-outline text-6xl text-base-content/40"></span>
                                {filtersActive ? (
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
