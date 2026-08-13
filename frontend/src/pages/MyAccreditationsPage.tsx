import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import useSWR from 'swr';
import { ApiError, listApplications, withdrawApplication } from '../api/client';
import type { Application, ApplicationStatus } from '../api/types';
import { accreditationScopeLabel, applicationStatusLabel } from '../logic/accreditationLabels';
import { formatDate } from '../logic/formatDate';

const STATUS_BADGE_CLASS: Record<ApplicationStatus, string> = {
    requested: 'badge-info',
    approved: 'badge-success',
    denied: 'badge-error',
    blacklisted: 'badge-warning',
};

export function MyAccreditationsPage() {
    const { i18n } = useLingui();
    const { data, error, isLoading, mutate } = useSWR<Application[]>('/api/applications', () => listApplications());
    const [listError, setListError] = useState<string | null>(null);

    const handleWithdraw = async (application: Application) => {
        setListError(null);
        try {
            await withdrawApplication(application.id);
            await mutate();
        } catch (err) {
            setListError(
                err instanceof ApiError ? err.message : i18n._(t`Antrag konnte nicht zurückgezogen werden.`),
            );
        }
    };

    return (
        <section className="flex flex-col gap-6">
            <h1 className="text-3xl font-bold">{i18n._(t`Meine Akkreditierungen`)}</h1>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Anträge konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {listError ? (
                <div role="alert" className="alert alert-error">
                    <span>{listError}</span>
                </div>
            ) : null}

            {data && data.length === 0 && !isLoading && !error ? (
                <p className="text-base-content/70">{i18n._(t`Noch keine Anträge vorhanden.`)}</p>
            ) : null}

            {data && data.length > 0 && !isLoading && !error ? (
                <div className="flex flex-col gap-4">
                    {data.map((application) => {
                        const accreditation = application.accreditation;
                        return (
                            <article
                                key={application.id}
                                className="card border border-base-300 bg-base-100 p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h2 className="text-lg font-semibold">{accreditation?.category?.name ?? ''}</h2>
                                        <div className="mt-1 flex flex-wrap items-center gap-2">
                                            <span className="badge badge-outline badge-sm">
                                                {accreditation ? accreditationScopeLabel(accreditation.scope, i18n) : ''}
                                            </span>
                                            {accreditation?.event ? (
                                                <span className="badge badge-info badge-sm">{accreditation.event.title}</span>
                                            ) : null}
                                            {accreditation?.deadline_end ? (
                                                <span className="badge badge-warning badge-sm">
                                                    {formatDate(accreditation.deadline_end, i18n.locale)}
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>
                                    <div className="flex flex-col items-end gap-2">
                                        <span className={`badge badge-sm ${STATUS_BADGE_CLASS[application.status]}`}>
                                            {applicationStatusLabel(application.status, i18n)}
                                        </span>
                                        {application.status === 'requested' ? (
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline"
                                                onClick={() => void handleWithdraw(application)}
                                            >
                                                {i18n._(t`Zurückziehen`)}
                                            </button>
                                        ) : null}
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
