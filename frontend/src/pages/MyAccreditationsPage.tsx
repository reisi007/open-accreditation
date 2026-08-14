import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import useSWR, { useSWRConfig } from 'swr';
import {
    ApiError,
    applySubAccreditation,
    listApplications,
    listSubAccreditations,
    listSubApplications,
    withdrawApplication,
    withdrawSubApplication,
} from '../api/client';
import type { Application, ApplicationStatus, SubAccreditation, SubApplication } from '../api/types';
import {
    accreditationScopeLabel,
    applicationStatusLabel,
    subAvailabilityLabel,
    subTypeLabel,
} from '../logic/accreditationLabels';
import { formatDate } from '../logic/formatDate';

const STATUS_BADGE_CLASS: Record<ApplicationStatus, string> = {
    requested: 'badge-info',
    approved: 'badge-success',
    denied: 'badge-error',
    blacklisted: 'badge-warning',
};

interface SubAccreditationSectionProps {
    accreditationId: number;
    subApplications: SubApplication[] | undefined;
}

function SubAccreditationSection({ accreditationId, subApplications }: SubAccreditationSectionProps) {
    const { i18n } = useLingui();
    const { mutate: globalMutate } = useSWRConfig();
    const { data, error, isLoading, mutate } = useSWR<SubAccreditation[]>(
        ['/api/accreditations', accreditationId, 'sub-accreditations'],
        () => listSubAccreditations(accreditationId),
    );
    const [actionError, setActionError] = useState<string | null>(null);

    const mySubApplications = (subApplications ?? []).filter(
        (subApplication) => subApplication.accreditation?.id === accreditationId,
    );

    const refreshAll = async () => {
        await mutate();
        await globalMutate('/api/sub-applications');
    };

    const handleApply = async (sub: SubAccreditation) => {
        setActionError(null);
        try {
            await applySubAccreditation(sub.id);
            await refreshAll();
        } catch (err) {
            setActionError(
                err instanceof ApiError ? err.message : i18n._(t`Sub-Antrag konnte nicht gesendet werden.`),
            );
        }
    };

    const handleWithdraw = async (subApplication: SubApplication) => {
        setActionError(null);
        try {
            await withdrawSubApplication(subApplication.id);
            await refreshAll();
        } catch (err) {
            setActionError(
                err instanceof ApiError ? err.message : i18n._(t`Sub-Antrag konnte nicht zurückgezogen werden.`),
            );
        }
    };

    if (isLoading) {
        return <span className="loading loading-spinner loading-sm"></span>;
    }

    if (error) {
        return (
            <div role="alert" className="alert alert-error">
                <span>{i18n._(t`Sub-Akkreditierungen konnten nicht geladen werden.`)}</span>
            </div>
        );
    }

    if (!data || data.length === 0) {
        return null;
    }

    return (
        <section className="mt-4 rounded-lg border border-base-300 bg-base-200/50 p-3">
            <h3 className="text-base font-semibold">{i18n._(t`Sub-Akkreditierungen (Park/Sitz)`)}</h3>

            {actionError ? (
                <div role="alert" className="alert alert-error mt-2">
                    <span>{actionError}</span>
                </div>
            ) : null}

            <div className="mt-2 flex flex-col gap-2">
                {data.map((sub) => {
                    const mine = mySubApplications.find(
                        (subApplication) => subApplication.sub_accreditation?.id === sub.id,
                    );
                    const deadlineText =
                        sub.deadline_end !== null
                            ? `${i18n._(t`Frist`)}: ${formatDate(sub.deadline_end, i18n.locale)}`
                            : '';
                    return (
                        <article key={sub.id} className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="badge badge-outline badge-sm">{subTypeLabel(sub.type, i18n)}</span>
                                <span
                                    className={`badge badge-sm ${
                                        sub.available > 0 ? 'badge-success' : 'badge-warning'
                                    }`}
                                >
                                    {subAvailabilityLabel(sub.available, i18n)}
                                </span>
                                {deadlineText !== '' ? (
                                    <span className="badge badge-warning badge-sm">{deadlineText}</span>
                                ) : null}
                                {mine ? (
                                    <span className={`badge badge-sm ${STATUS_BADGE_CLASS[mine.status]}`}>
                                        {applicationStatusLabel(mine.status, i18n)}
                                    </span>
                                ) : null}
                            </div>
                            {mine ? (
                                mine.status === 'requested' ? (
                                    <button
                                        type="button"
                                        className="btn btn-outline btn-sm"
                                        onClick={() => void handleWithdraw(mine)}
                                    >
                                        {i18n._(t`Zurückziehen`)}
                                    </button>
                                ) : null
                            ) : (
                                <button
                                    type="button"
                                    className="btn btn-primary btn-sm"
                                    onClick={() => void handleApply(sub)}
                                >
                                    {i18n._(t`Beantragen`)}
                                </button>
                            )}
                        </article>
                    );
                })}
            </div>
        </section>
    );
}

export function MyAccreditationsPage() {
    const { i18n } = useLingui();
    const { data, error, isLoading, mutate } = useSWR<Application[]>('/api/applications', () => listApplications());
    const { data: subApplications, error: subApplicationsError } = useSWR<SubApplication[]>(
        '/api/sub-applications',
        () => listSubApplications(),
    );
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

            {subApplicationsError ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Sub-Anträge konnten nicht geladen werden.`)}</span>
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
                                {application.status === 'approved' && accreditation ? (
                                    <SubAccreditationSection
                                        accreditationId={accreditation.id}
                                        subApplications={subApplications}
                                    />
                                ) : null}
                            </article>
                        );
                    })}
                </div>
            ) : null}
        </section>
    );
}
