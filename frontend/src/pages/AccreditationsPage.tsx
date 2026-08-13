import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import useSWR from 'swr';
import { listAccreditations } from '../api/client';
import type { Accreditation } from '../api/types';
import { ApplyButton } from '../components/ApplyButton';
import { accreditationScopeLabel, availabilityLabel } from '../logic/accreditationLabels';
import { formatDate } from '../logic/formatDate';

export function AccreditationsPage() {
    const { i18n } = useLingui();
    const { data, error, isLoading } = useSWR<Accreditation[]>('/api/accreditations', () => listAccreditations());

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
                <p className="text-base-content/70">{i18n._(t`Keine Akkreditierungen verfügbar.`)}</p>
            ) : null}

            {data && data.length > 0 && !isLoading && !error ? (
                <div className="flex flex-col gap-4">
                    {data.map((accreditation) => {
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
