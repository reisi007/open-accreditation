import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import useSWR, { useSWRConfig } from 'swr';
import {
    ApiError,
    applySubAccreditation,
    downloadApplicationWallet,
    downloadSubApplicationWallet,
    listApplications,
    listSubAccreditations,
    listSubApplications,
    withdrawApplication,
    withdrawSubApplication,
    type WalletProvider,
} from '../api/client';
import type { Application, ApplicationStatus, SubAccreditation, SubApplication } from '../api/types';
import {
    accreditationScopeLabel,
    applicationStatusLabel,
    subAvailabilityLabel,
    subTypeLabel,
} from '../logic/accreditationLabels';
import { downloadBlob } from '../logic/downloadBlob';
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
    const [walletBusy, setWalletBusy] = useState(false);

    const mySubApplications = (subApplications ?? []).filter(
        (subApplication) => subApplication.accreditation?.id === accreditationId,
    );

    const refreshAll = async () => {
        await mutate();
        await globalMutate('/api/sub-applications');
    };

    const handleWalletDownload = async (subApplicationId: number) => {
        setActionError(null);
        setWalletBusy(true);
        try {
            const { blob, filename } = await downloadSubApplicationWallet(subApplicationId);
            downloadBlob(blob, filename);
        } catch (err) {
            setActionError(
                err instanceof ApiError ? err.message : i18n._(t`Wallet-Pass konnte nicht heruntergeladen werden.`),
            );
        } finally {
            setWalletBusy(false);
        }
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
                                ) : mine.status === 'approved' ? (
                                    <button
                                        type="button"
                                        className="btn btn-outline btn-sm"
                                        disabled={walletBusy}
                                        onClick={() => void handleWalletDownload(mine.id)}
                                    >
                                        {walletBusy ? <span className="loading loading-spinner loading-xs"></span> : null}
                                        {i18n._(t`Apple Wallet`)}
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
    const [walletError, setWalletError] = useState<string | null>(null);
    const [walletBusy, setWalletBusy] = useState<WalletProvider | null>(null);

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

    /**
     * The wallet buttons used to be bare `<a download>` anchors, so a non-2xx
     * (e.g. a revoked approval) silently saved the JSON error body as
     * `wallet.json`/`wallet.pkpass` with no feedback at all. Going through the
     * API client turns the failure into a visible `ApiError` message.
     */
    const handleWalletDownload = async (applicationId: number, provider: WalletProvider) => {
        setWalletError(null);
        setWalletBusy(provider);
        try {
            const { blob, filename } = await downloadApplicationWallet(applicationId, provider);
            downloadBlob(blob, filename);
        } catch (err) {
            setWalletError(
                err instanceof ApiError ? err.message : i18n._(t`Wallet-Pass konnte nicht heruntergeladen werden.`),
            );
        } finally {
            setWalletBusy(null);
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

            {walletError ? (
                <div role="alert" className="alert alert-error">
                    <span>{walletError}</span>
                </div>
            ) : null}

            {data && data.length === 0 && !isLoading && !error ? (
                <div className="card border border-base-300 bg-base-100">
                    <div className="card-body items-center justify-center py-16 text-center">
                        <span className="iconify mdi--badge-account-outline text-6xl text-base-content/40"></span>
                        <h2 className="card-title">{i18n._(t`Noch keine Anträge`)}</h2>
                        <p className="text-base-content/70">
                            {i18n._(t`Sobald du dich für eine Akkreditierung beworben hast, erscheint dein Antrag hier.`)}
                        </p>
                        <Link to="/akkreditierungen" className="btn btn-primary mt-2">
                            {i18n._(t`Akkreditierungen ansehen`)}
                        </Link>
                    </div>
                </div>
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
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0">
                                        <h2 className="text-lg font-semibold">
                                            {accreditation?.category?.name ?? ''}
                                        </h2>
                                        <p className="mt-0.5 text-sm text-base-content/60">#{application.id}</p>
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
                                    <div className="flex flex-col items-start gap-2 sm:items-end">
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
                                        {application.status === 'approved' ? (
                                            <div
                                                role="group"
                                                aria-label={i18n._(t`Wallet-Downloads`)}
                                                className="flex flex-col gap-2"
                                            >
                                                <button
                                                    type="button"
                                                    className="btn btn-outline btn-sm"
                                                    disabled={walletBusy !== null}
                                                    onClick={() => void handleWalletDownload(application.id, 'apple')}
                                                >
                                                    {walletBusy === 'apple' ? (
                                                        <span className="loading loading-spinner loading-xs"></span>
                                                    ) : null}
                                                    {i18n._(t`Apple Wallet`)}
                                                </button>
                                                <button
                                                    type="button"
                                                    className="btn btn-outline btn-sm"
                                                    disabled={walletBusy !== null}
                                                    onClick={() => void handleWalletDownload(application.id, 'google')}
                                                >
                                                    {walletBusy === 'google' ? (
                                                        <span className="loading loading-spinner loading-xs"></span>
                                                    ) : null}
                                                    {i18n._(t`Google Wallet`)}
                                                </button>
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                                {application.status === 'approved' ? (
                                    <p className="mt-3 border-t border-base-300 pt-3 text-sm text-base-content/60">
                                        {i18n._(t`Pass wird im Apple/Google-Wallet-Format heruntergeladen.`)}
                                    </p>
                                ) : null}
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
