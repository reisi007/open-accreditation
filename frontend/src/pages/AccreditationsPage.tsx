import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import useSWR from 'swr';
import { listAccreditations } from '../api/client';
import type { Accreditation } from '../api/types';
import { ApplyButton } from '../components/ApplyButton';
import { accreditationScopeLabel, availabilityLabel } from '../logic/accreditationLabels';
import { formatDate } from '../logic/formatDate';

const PAGE_SIZE = 20;

export function AccreditationsPage() {
    const { i18n } = useLingui();
    const { data, error, isLoading } = useSWR<Accreditation[]>('/api/accreditations', () => listAccreditations());
    const [page, setPage] = useState(1);

    const totalCount = data?.length ?? 0;
    const pageCount = Math.max(1, Math.ceil(totalCount / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount);
    // Newest first (backend orders by category name, which would bury newly
    // created rows behind the 20-row page boundary and break the E2E flow).
    const orderedAccreditations = [...(data ?? [])].sort((a, b) => b.id - a.id);
    const pagedAccreditations = orderedAccreditations.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

    return (
        <section className="flex flex-col gap-6">
            <h1 className="text-3xl font-bold">{i18n._(t`Akkreditierungen`)}</h1>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Akkreditierungen konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {data && data.length === 0 && !isLoading && !error ? (
                <div className="card border border-base-300 bg-base-100">
                    <div className="card-body items-center justify-center py-16 text-center">
                        <span className="iconify mdi--badge-account-outline text-6xl text-base-content/40"></span>
                        <h2 className="card-title">{i18n._(t`Keine Akkreditierungen verfügbar.`)}</h2>
                        <p className="text-base-content/70">
                            {i18n._(t`Zurzeit gibt es keine Akkreditierungen, für die du dich bewerben kannst.`)}
                        </p>
                        <Link to="/" className="btn btn-primary mt-2">
                            {i18n._(t`Zur Startseite`)}
                        </Link>
                    </div>
                </div>
            ) : null}

            {data && data.length > 0 && !isLoading && !error ? (
                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <p aria-live="polite" className="text-sm text-base-content/70">
                            {totalCount === 1 ? '1 Akkreditierung' : `${totalCount} Akkreditierungen`}
                        </p>
                        {pageCount > 1 ? (
                            <div className="join" role="group" aria-label={i18n._(t`Seitennavigation`)}>
                                <button
                                    type="button"
                                    className="btn btn-sm join-item"
                                    disabled={currentPage <= 1}
                                    onClick={() => setPage((previous) => Math.max(1, previous - 1))}
                                >
                                    {i18n._(t`Zurück`)}
                                </button>
                                <span className="join-item btn btn-sm btn-disabled" aria-live="polite">
                                    {i18n._(t`Seite ${currentPage} von ${pageCount}`)}
                                </span>
                                <button
                                    type="button"
                                    className="btn btn-sm join-item"
                                    disabled={currentPage >= pageCount}
                                    onClick={() => setPage((previous) => Math.min(pageCount, previous + 1))}
                                >
                                    {i18n._(t`Weiter`)}
                                </button>
                            </div>
                        ) : null}
                    </div>
                    {pagedAccreditations.map((accreditation) => {
                        const deadlineText =
                            accreditation.deadline_end !== null
                                ? `${i18n._(t`Frist`)}: ${formatDate(accreditation.deadline_end, i18n.locale)}`
                                : '';
                        return (
                            <article
                                key={accreditation.id}
                                className="card border border-base-300 bg-base-100 p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h2 className="text-lg font-semibold">{accreditation.category?.name ?? ''}</h2>
                                        <div className="mt-1 flex flex-wrap items-center gap-2">
                                            <span className="badge badge-outline badge-sm">
                                                {accreditationScopeLabel(accreditation.scope, i18n)}
                                            </span>
                                            {accreditation.event ? (
                                                <span className="badge badge-info badge-sm">{accreditation.event.title}</span>
                                            ) : null}
                                            {accreditation.team ? (
                                                <span className="badge badge-ghost badge-sm">{accreditation.team.name}</span>
                                            ) : null}
                                            {deadlineText !== '' ? (
                                                <span className="badge badge-warning badge-sm">{deadlineText}</span>
                                            ) : null}
                                        </div>
                                    </div>
                                    <div className="flex flex-col items-end gap-2">
                                        <span
                                            className={`badge badge-sm ${
                                                accreditation.available > 0 ? 'badge-success' : 'badge-warning'
                                            }`}
                                        >
                                            {availabilityLabel(accreditation.available, i18n)}
                                        </span>
                                        <ApplyButton accreditationId={accreditation.id} />
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            ) : null}
        </section>
    );
}
