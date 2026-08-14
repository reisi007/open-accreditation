import type { I18n } from '@lingui/core';
import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import useSWR from 'swr';
import {
    ApiError,
    allocateAccreditation,
    createBlacklist,
    deleteBlacklist,
    exportBadges,
    listAdminAccreditations,
    listAdminApplicationMedia,
    listAdminApplications,
    listAdminSubAccreditations,
    listAdminSubApplications,
    listBadgeTemplates,
    listBlacklists,
    updateAdminApplication,
    updateAdminSubApplication,
} from '../../api/client';
import type {
    Accreditation,
    AdminApplication,
    AdminMedia,
    AdminSubApplication,
    AllocationResult,
    ApplicationStatus,
    BadgeTemplate,
    Blacklist,
    SubAccreditation,
    SubApplicationStatus,
} from '../../api/types';
import { applicationStatusLabel, subTypeLabel } from '../../logic/accreditationLabels';
import { downloadBlob } from '../../logic/downloadBlob';
import { BlacklistForm } from './BlacklistForm';
import { DenyModal } from './DenyModal';
import { buildAllocationPayload, buildApplicationAction, buildBlacklistPayload, type BlacklistFormValues } from './approvalFormUtils';

type ApprovalsTab = 'applications' | 'subapplications' | 'blacklist';

function firstErrorMessage(err: unknown, fallback: string): string {
    if (err instanceof ApiError) {
        const first = Object.values(err.info.errors ?? {}).flat().find((entry): entry is string => typeof entry === 'string');
        if (first !== undefined && first !== '') {
            return first;
        }

        return err.message;
    }

    return fallback;
}

function statusBadgeClass(status: ApplicationStatus | SubApplicationStatus): string {
    switch (status) {
        case 'approved':
            return 'badge-success';
        case 'denied':
            return 'badge-error';
        case 'blacklisted':
        case 'requested':
            return 'badge-warning';
    }
}

function mediaTypeLabel(type: string, i18n: I18n): string {
    switch (type) {
        case 'portrait':
            return i18n._(t`Porträt`);
        case 'press_id':
            return i18n._(t`Presse-ID`);
        case 'attachment':
            return i18n._(t`Anhang`);
        default:
            return type;
    }
}

function formatDateTime(iso: string, i18n: I18n): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    return date.toLocaleString(i18n.locale ?? undefined);
}

async function fetchAllAdminSubAccreditations(accreditations: Accreditation[]): Promise<SubAccreditation[]> {
    const lists = await Promise.all(accreditations.map((accreditation) => listAdminSubAccreditations(accreditation.id)));

    return lists.flat();
}

interface ApplicationRowProps {
    application: AdminApplication;
    onChanged: () => Promise<void>;
    onDeny: (application: AdminApplication) => void;
}

function ApplicationRow({ application, onChanged, onDeny }: ApplicationRowProps) {
    const { i18n } = useLingui();
    const [priority, setPriority] = useState(application.priority);
    const [actionError, setActionError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const { data: media, isLoading: mediaLoading } = useSWR<AdminMedia[]>(
        `/api/admin/applications/${application.id}/media`,
        () => listAdminApplicationMedia(application.id),
    );

    const handleTogglePriority = async (next: boolean) => {
        setPriority(next);
        setActionError(null);
        try {
            await updateAdminApplication(application.id, buildApplicationAction({ priority: next }));
            await onChanged();
        } catch (err) {
            setPriority(application.priority);
            setActionError(firstErrorMessage(err, i18n._(t`VIP-Status konnte nicht geändert werden.`)));
        }
    };

    const handleApprove = async () => {
        setActionError(null);
        setBusy(true);
        try {
            await updateAdminApplication(application.id, buildApplicationAction({ status: 'approved' }));
            await onChanged();
        } catch (err) {
            setActionError(firstErrorMessage(err, i18n._(t`Freigabe fehlgeschlagen.`)));
        } finally {
            setBusy(false);
        }
    };

    const canApprove = application.status === 'requested' || application.status === 'denied';
    const canDeny = application.status === 'requested' || application.status === 'approved';

    return (
        <tr>
            <td>
                <div className="font-medium">{application.user?.name ?? ''}</div>
                <div className="text-sm text-base-content/70">{application.user?.email ?? ''}</div>
            </td>
            <td>
                <div className="flex flex-wrap gap-1">
                    {application.accreditation?.category ? (
                        <span className="badge badge-info badge-sm">{application.accreditation.category.name}</span>
                    ) : null}
                    {application.accreditation?.event ? (
                        <span className="badge badge-outline badge-sm">{application.accreditation.event.title}</span>
                    ) : null}
                    {application.accreditation?.team ? (
                        <span className="badge badge-ghost badge-sm">{application.accreditation.team.name}</span>
                    ) : null}
                </div>
            </td>
            <td>
                <div className="flex flex-col gap-1">
                    <span className={`badge badge-sm ${statusBadgeClass(application.status)}`}>
                        {applicationStatusLabel(application.status, i18n)}
                    </span>
                    {application.reason ? (
                        <span className="text-xs text-base-content/60">{application.reason}</span>
                    ) : null}
                </div>
            </td>
            <td>
                <label className="label cursor-pointer gap-2">
                    <input
                        type="checkbox"
                        className="toggle toggle-sm"
                        checked={priority}
                        aria-label={i18n._(t`VIP`)}
                        onChange={(event) => void handleTogglePriority(event.target.checked)}
                    />
                </label>
            </td>
            <td>
                {mediaLoading ? <span className="loading loading-spinner loading-sm"></span> : null}
                {media && media.length > 0 ? (
                    <div className="flex flex-wrap gap-2">
                        {media.map((item) =>
                            item.type === 'attachment' ? (
                                <a
                                    key={item.id}
                                    href={item.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    aria-label={i18n._(t`Anhang öffnen`)}
                                >
                                    <span className="iconify mdi--paperclip text-2xl"></span>
                                </a>
                            ) : (
                                <a key={item.id} href={item.url} target="_blank" rel="noreferrer">
                                    <img
                                        src={item.url}
                                        className="h-20 w-16 rounded object-cover"
                                        alt={mediaTypeLabel(item.type, i18n)}
                                    />
                                </a>
                            ),
                        )}
                    </div>
                ) : null}
            </td>
            <td>
                {actionError ? (
                    <p role="alert" className="mb-2 max-w-48 text-sm text-error">
                        {actionError}
                    </p>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    {canApprove ? (
                        <button type="button" className="btn btn-sm btn-success" disabled={busy} onClick={() => void handleApprove()}>
                            {i18n._(t`Freigeben`)}
                        </button>
                    ) : null}
                    {application.status === 'approved' ? (
                        <button type="button" className="btn btn-sm btn-error btn-outline" onClick={() => onDeny(application)}>
                            {i18n._(t`Freigabe entziehen`)}
                        </button>
                    ) : null}
                    {canDeny ? (
                        <button type="button" className="btn btn-sm btn-error btn-outline" onClick={() => onDeny(application)}>
                            {i18n._(t`Ablehnen`)}
                        </button>
                    ) : null}
                </div>
            </td>
        </tr>
    );
}

function ApplicationsTab() {
    const { i18n } = useLingui();
    const [accreditationFilter, setAccreditationFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState<'' | ApplicationStatus>('');
    const [allocationLimit, setAllocationLimit] = useState('5');
    const [allocationResult, setAllocationResult] = useState<AllocationResult | null>(null);
    const [allocationError, setAllocationError] = useState<string | null>(null);
    const [allocationBusy, setAllocationBusy] = useState(false);

    const [denyTarget, setDenyTarget] = useState<AdminApplication | null>(null);
    const [denyError, setDenyError] = useState<string | null>(null);
    const [denyBusy, setDenyBusy] = useState(false);

    const [exportTemplateId, setExportTemplateId] = useState('');
    const [exportError, setExportError] = useState<string | null>(null);
    const [exportBusy, setExportBusy] = useState(false);

    const { data: accreditations, mutate: mutateAccreditations } = useSWR<Accreditation[]>(
        '/api/admin/accreditations',
        () => listAdminAccreditations(),
    );

    const { data: badgeTemplates } = useSWR<BadgeTemplate[]>('/api/admin/badge-templates', () =>
        listBadgeTemplates(),
    );

    const { data, error, isLoading, mutate } = useSWR<AdminApplication[]>(
        ['/api/admin/applications', accreditationFilter, statusFilter],
        () =>
            listAdminApplications({
                accreditation_id: accreditationFilter === '' ? undefined : Number(accreditationFilter),
                status: statusFilter === '' ? undefined : statusFilter,
            }),
    );

    const revalidate = async () => {
        await Promise.all([mutate(), mutateAccreditations()]);
    };

    const handleAllocate = async (mode: 'all' | 'first') => {
        if (accreditationFilter === '') return;
        setAllocationError(null);
        setAllocationResult(null);
        setAllocationBusy(true);
        try {
            const result = await allocateAccreditation(
                Number(accreditationFilter),
                buildAllocationPayload(mode, mode === 'first' ? Number(allocationLimit) : undefined),
            );
            setAllocationResult(result);
            await revalidate();
        } catch (err) {
            setAllocationError(firstErrorMessage(err, i18n._(t`Massenfreigabe fehlgeschlagen.`)));
        } finally {
            setAllocationBusy(false);
        }
    };

    const handleExport = async (format: 'pdf' | 'csv') => {
        if (accreditationFilter === '') return;
        setExportError(null);
        setExportBusy(true);
        try {
            const accreditationId = Number(accreditationFilter);
            const payload =
                badgeTemplates && badgeTemplates.length > 0
                    ? { format, template_id: exportTemplateId === '' ? undefined : Number(exportTemplateId) }
                    : { format };
            const blob = await exportBadges(accreditationId, payload);
            downloadBlob(blob, format === 'pdf' ? `badges-${accreditationId}.pdf` : 'badges.csv');
        } catch (err) {
            setExportError(firstErrorMessage(err, i18n._(t`Export fehlgeschlagen.`)));
        } finally {
            setExportBusy(false);
        }
    };

    const openDeny = (application: AdminApplication) => {
        setDenyTarget(application);
        setDenyError(null);
    };

    const handleDenyConfirm = async (reason: string) => {
        if (!denyTarget) return;
        setDenyError(null);
        setDenyBusy(true);
        try {
            await updateAdminApplication(denyTarget.id, buildApplicationAction({ status: 'denied', reason }));
            setDenyTarget(null);
            await revalidate();
        } catch (err) {
            setDenyError(firstErrorMessage(err, i18n._(t`Ablehnung fehlgeschlagen.`)));
        } finally {
            setDenyBusy(false);
        }
    };

    const firstLimitValid = allocationLimit !== '' && Number(allocationLimit) >= 1;

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-wrap items-end gap-4">
                <div className="form-control">
                    <label className="label" htmlFor="approval-accreditation-filter">
                        <span className="label-text">{i18n._(t`Akkreditierung`)}</span>
                    </label>
                    <select
                        id="approval-accreditation-filter"
                        className="select select-sm"
                        value={accreditationFilter}
                        onChange={(event) => setAccreditationFilter(event.target.value)}
                    >
                        <option value="">{i18n._(t`Alle`)}</option>
                        {(accreditations ?? []).map((accreditation) => (
                            <option key={accreditation.id} value={String(accreditation.id)}>
                                {accreditation.category?.name ?? ''} · {accreditation.event?.title ?? ''}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="approval-status-filter">
                        <span className="label-text">{i18n._(t`Status`)}</span>
                    </label>
                    <select
                        id="approval-status-filter"
                        className="select select-sm"
                        value={statusFilter}
                        onChange={(event) => setStatusFilter(event.target.value as '' | ApplicationStatus)}
                    >
                        <option value="">{i18n._(t`Alle`)}</option>
                        <option value="requested">{applicationStatusLabel('requested', i18n)}</option>
                        <option value="approved">{applicationStatusLabel('approved', i18n)}</option>
                        <option value="denied">{applicationStatusLabel('denied', i18n)}</option>
                    </select>
                </div>
            </div>

            {accreditationFilter !== '' ? (
                <div className="card bg-base-200">
                    <div className="card-body">
                        <h2 className="card-title text-base">{i18n._(t`Massenfreigabe`)}</h2>
                        {allocationError ? (
                            <div role="alert" className="alert alert-error">
                                <span>{allocationError}</span>
                            </div>
                        ) : null}
                        {allocationResult ? (
                            <div role="status" className="alert alert-info">
                                <div className="flex flex-col gap-1">
                                    <span>
                                        {i18n._(t`Freigegeben`)}: {allocationResult.approved} · {i18n._(t`Abgelehnt`)}:{' '}
                                        {allocationResult.denied} · {i18n._(t`Übersprungen (Blacklist)`)}:{' '}
                                        {allocationResult.skipped_blacklist}
                                    </span>
                                    <span className="text-sm">
                                        {i18n._(t`Übersprungene Anträge sind auf der Blacklist und bleiben beantragt.`)}
                                    </span>
                                </div>
                            </div>
                        ) : null}
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="form-control">
                                <label className="label" htmlFor="approval-allocation-limit">
                                    <span className="label-text">{i18n._(t`Anzahl`)}</span>
                                </label>
                                <input
                                    id="approval-allocation-limit"
                                    type="number"
                                    min={1}
                                    className="input input-sm w-24"
                                    value={allocationLimit}
                                    onChange={(event) => setAllocationLimit(event.target.value)}
                                />
                            </div>
                            <button
                                type="button"
                                className="btn btn-sm btn-primary"
                                disabled={allocationBusy}
                                onClick={() => void handleAllocate('all')}
                            >
                                {i18n._(t`Alle freigeben`)}
                            </button>
                            <button
                                type="button"
                                className="btn btn-sm btn-outline"
                                disabled={allocationBusy || !firstLimitValid}
                                onClick={() => void handleAllocate('first')}
                            >
                                {i18n._(t`Erste X freigeben`)}
                            </button>
                        </div>
                        <div className="mt-4 border-t border-base-300 pt-4">
                            <h3 className="text-sm font-semibold">{i18n._(t`Ausweis-Export`)}</h3>
                            {exportError ? (
                                <div role="alert" className="alert alert-error mt-2">
                                    <span>{exportError}</span>
                                </div>
                            ) : null}
                            <div className="mt-2 flex flex-wrap items-end gap-4">
                                {badgeTemplates && badgeTemplates.length > 0 ? (
                                    <div className="form-control">
                                        <label className="label" htmlFor="approval-export-template">
                                            <span className="label-text">{i18n._(t`Template`)}</span>
                                        </label>
                                        <select
                                            id="approval-export-template"
                                            className="select select-sm"
                                            value={exportTemplateId}
                                            onChange={(event) => setExportTemplateId(event.target.value)}
                                        >
                                            <option value="">{i18n._(t`Standard`)}</option>
                                            {badgeTemplates.map((template) => (
                                                <option key={template.id} value={String(template.id)}>
                                                    {template.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                ) : null}
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline"
                                    disabled={exportBusy}
                                    onClick={() => void handleExport('pdf')}
                                >
                                    {exportBusy ? <span className="loading loading-spinner loading-xs"></span> : null}
                                    PDF
                                </button>
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline"
                                    disabled={exportBusy}
                                    onClick={() => void handleExport('csv')}
                                >
                                    CSV
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            ) : null}

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Anträge konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {data && !isLoading && !error ? (
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>{i18n._(t`Antragsteller`)}</th>
                                <th>{i18n._(t`Akkreditierung`)}</th>
                                <th>{i18n._(t`Status`)}</th>
                                <th>{i18n._(t`VIP`)}</th>
                                <th>{i18n._(t`Medien`)}</th>
                                <th>{i18n._(t`Aktionen`)}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((application) => (
                                <ApplicationRow
                                    key={application.id}
                                    application={application}
                                    onChanged={revalidate}
                                    onDeny={openDeny}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}

            {data && data.length === 0 && !isLoading && !error ? (
                <p className="text-base-content/70">{i18n._(t`Keine Anträge vorhanden.`)}</p>
            ) : null}

            {denyTarget ? (
                <DenyModal
                    title={denyTarget.status === 'approved' ? i18n._(t`Freigabe entziehen`) : i18n._(t`Antrag ablehnen`)}
                    error={denyError}
                    isSubmitting={denyBusy}
                    onConfirm={handleDenyConfirm}
                    onCancel={() => setDenyTarget(null)}
                />
            ) : null}
        </div>
    );
}

interface SubApplicationRowProps {
    application: AdminSubApplication;
    onChanged: () => Promise<void>;
    onDeny: (application: AdminSubApplication) => void;
}

function SubApplicationRow({ application, onChanged, onDeny }: SubApplicationRowProps) {
    const { i18n } = useLingui();
    const [priority, setPriority] = useState(application.priority);
    const [actionError, setActionError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const handleTogglePriority = async (next: boolean) => {
        setPriority(next);
        setActionError(null);
        try {
            await updateAdminSubApplication(application.id, buildApplicationAction({ priority: next }));
            await onChanged();
        } catch (err) {
            setPriority(application.priority);
            setActionError(firstErrorMessage(err, i18n._(t`VIP-Status konnte nicht geändert werden.`)));
        }
    };

    const handleApprove = async () => {
        setActionError(null);
        setBusy(true);
        try {
            await updateAdminSubApplication(application.id, buildApplicationAction({ status: 'approved' }));
            await onChanged();
        } catch (err) {
            setActionError(firstErrorMessage(err, i18n._(t`Freigabe fehlgeschlagen.`)));
        } finally {
            setBusy(false);
        }
    };

    const canApprove = application.status === 'requested' || application.status === 'denied';
    const canDeny = application.status === 'requested' || application.status === 'approved';

    return (
        <tr>
            <td>
                <div className="flex flex-col gap-1">
                    {application.sub_accreditation ? (
                        <span className="badge badge-outline badge-sm">
                            {subTypeLabel(application.sub_accreditation.type, i18n)}
                        </span>
                    ) : null}
                    {application.sub_accreditation ? (
                        <span className="text-xs text-base-content/60">
                            {i18n._(t`Quota`)} {application.sub_accreditation.quota} · {i18n._(t`Verfügbar`)}{' '}
                            {application.sub_accreditation.available}
                        </span>
                    ) : null}
                </div>
            </td>
            <td>
                <div className="font-medium">{application.user?.name ?? ''}</div>
                <div className="text-sm text-base-content/70">{application.user?.email ?? ''}</div>
            </td>
            <td>
                <div className="flex flex-wrap gap-1">
                    {application.accreditation?.category ? (
                        <span className="badge badge-info badge-sm">{application.accreditation.category.name}</span>
                    ) : null}
                    {application.accreditation?.event ? (
                        <span className="badge badge-outline badge-sm">{application.accreditation.event.title}</span>
                    ) : null}
                </div>
            </td>
            <td>
                <div className="flex flex-col gap-1">
                    <span className={`badge badge-sm ${statusBadgeClass(application.status)}`}>
                        {applicationStatusLabel(application.status, i18n)}
                    </span>
                    {application.reason ? (
                        <span className="text-xs text-base-content/60">{application.reason}</span>
                    ) : null}
                </div>
            </td>
            <td>
                <label className="label cursor-pointer gap-2">
                    <input
                        type="checkbox"
                        className="toggle toggle-sm"
                        checked={priority}
                        aria-label={i18n._(t`VIP`)}
                        onChange={(event) => void handleTogglePriority(event.target.checked)}
                    />
                </label>
            </td>
            <td>
                {actionError ? (
                    <p role="alert" className="mb-2 max-w-48 text-sm text-error">
                        {actionError}
                    </p>
                ) : null}
                <div className="flex flex-wrap gap-2">
                    {canApprove ? (
                        <button type="button" className="btn btn-sm btn-success" disabled={busy} onClick={() => void handleApprove()}>
                            {i18n._(t`Freigeben`)}
                        </button>
                    ) : null}
                    {application.status === 'approved' ? (
                        <button type="button" className="btn btn-sm btn-error btn-outline" onClick={() => onDeny(application)}>
                            {i18n._(t`Freigabe entziehen`)}
                        </button>
                    ) : null}
                    {canDeny ? (
                        <button type="button" className="btn btn-sm btn-error btn-outline" onClick={() => onDeny(application)}>
                            {i18n._(t`Ablehnen`)}
                        </button>
                    ) : null}
                </div>
            </td>
        </tr>
    );
}

function SubApplicationsTab() {
    const { i18n } = useLingui();
    const [subAccreditationFilter, setSubAccreditationFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState<'' | SubApplicationStatus>('');

    const [denyTarget, setDenyTarget] = useState<AdminSubApplication | null>(null);
    const [denyError, setDenyError] = useState<string | null>(null);
    const [denyBusy, setDenyBusy] = useState(false);

    const { data: accreditations } = useSWR<Accreditation[]>('/api/admin/accreditations', () => listAdminAccreditations());

    const { data: allSubs } = useSWR<SubAccreditation[]>(
        accreditations ? ['/api/admin/sub-accreditations', accreditations.length] : null,
        () => (accreditations ? fetchAllAdminSubAccreditations(accreditations) : Promise.resolve([])),
    );

    const { data, error, isLoading, mutate } = useSWR<AdminSubApplication[]>(
        ['/api/admin/sub-applications', subAccreditationFilter, statusFilter],
        () =>
            listAdminSubApplications({
                sub_accreditation_id: subAccreditationFilter === '' ? undefined : Number(subAccreditationFilter),
                status: statusFilter === '' ? undefined : statusFilter,
            }),
    );

    const revalidate = async () => {
        await mutate();
    };

    const openDeny = (application: AdminSubApplication) => {
        setDenyTarget(application);
        setDenyError(null);
    };

    const handleDenyConfirm = async (reason: string) => {
        if (!denyTarget) return;
        setDenyError(null);
        setDenyBusy(true);
        try {
            await updateAdminSubApplication(denyTarget.id, buildApplicationAction({ status: 'denied', reason }));
            setDenyTarget(null);
            await revalidate();
        } catch (err) {
            setDenyError(firstErrorMessage(err, i18n._(t`Ablehnung fehlgeschlagen.`)));
        } finally {
            setDenyBusy(false);
        }
    };

    const subLabel = (sub: SubAccreditation) => {
        const accreditation = (accreditations ?? []).find((item) => item.id === sub.accreditation_id);
        const base = subTypeLabel(sub.type, i18n);

        return accreditation?.category?.name ? `${base} · ${accreditation.category.name}` : base;
    };

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-wrap items-end gap-4">
                <div className="form-control">
                    <label className="label" htmlFor="approval-sub-accreditation-filter">
                        <span className="label-text">{i18n._(t`Sub-Akkreditierung`)}</span>
                    </label>
                    <select
                        id="approval-sub-accreditation-filter"
                        className="select select-sm"
                        value={subAccreditationFilter}
                        onChange={(event) => setSubAccreditationFilter(event.target.value)}
                    >
                        <option value="">{i18n._(t`Alle`)}</option>
                        {(allSubs ?? []).map((sub) => (
                            <option key={sub.id} value={String(sub.id)}>
                                {subLabel(sub)}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="form-control">
                    <label className="label" htmlFor="approval-sub-status-filter">
                        <span className="label-text">{i18n._(t`Status`)}</span>
                    </label>
                    <select
                        id="approval-sub-status-filter"
                        className="select select-sm"
                        value={statusFilter}
                        onChange={(event) => setStatusFilter(event.target.value as '' | SubApplicationStatus)}
                    >
                        <option value="">{i18n._(t`Alle`)}</option>
                        <option value="requested">{applicationStatusLabel('requested', i18n)}</option>
                        <option value="approved">{applicationStatusLabel('approved', i18n)}</option>
                        <option value="denied">{applicationStatusLabel('denied', i18n)}</option>
                    </select>
                </div>
            </div>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Sub-Anträge konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {data && !isLoading && !error ? (
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>{i18n._(t`Sub-Akkreditierung`)}</th>
                                <th>{i18n._(t`Antragsteller`)}</th>
                                <th>{i18n._(t`Haupt-Akkreditierung`)}</th>
                                <th>{i18n._(t`Status`)}</th>
                                <th>{i18n._(t`VIP`)}</th>
                                <th>{i18n._(t`Aktionen`)}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((application) => (
                                <SubApplicationRow
                                    key={application.id}
                                    application={application}
                                    onChanged={revalidate}
                                    onDeny={openDeny}
                                />
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}

            {data && data.length === 0 && !isLoading && !error ? (
                <p className="text-base-content/70">{i18n._(t`Keine Sub-Anträge vorhanden.`)}</p>
            ) : null}

            {denyTarget ? (
                <DenyModal
                    title={denyTarget.status === 'approved' ? i18n._(t`Freigabe entziehen`) : i18n._(t`Sub-Antrag ablehnen`)}
                    error={denyError}
                    isSubmitting={denyBusy}
                    onConfirm={handleDenyConfirm}
                    onCancel={() => setDenyTarget(null)}
                />
            ) : null}
        </div>
    );
}

function BlacklistTab() {
    const { i18n } = useLingui();
    const { data, error, isLoading, mutate } = useSWR<Blacklist[]>(['/api/admin/blacklists', ''], () => listBlacklists());
    const [formError, setFormError] = useState<string | null>(null);

    const handleCreate = async (values: BlacklistFormValues) => {
        setFormError(null);
        try {
            await createBlacklist(buildBlacklistPayload(values));
            await mutate();
        } catch (err) {
            setFormError(firstErrorMessage(err, i18n._(t`Blacklist-Eintrag konnte nicht angelegt werden.`)));
            throw err;
        }
    };

    const handleDelete = async (entry: Blacklist) => {
        if (!window.confirm(i18n._(t`Blacklist-Eintrag wirklich löschen?`))) return;
        setFormError(null);
        try {
            await deleteBlacklist(entry.id);
            await mutate();
        } catch (err) {
            setFormError(firstErrorMessage(err, i18n._(t`Blacklist-Eintrag konnte nicht gelöscht werden.`)));
        }
    };

    return (
        <div className="flex flex-col gap-6">
            <div className="card bg-base-200">
                <div className="card-body">
                    <h2 className="card-title text-base">{i18n._(t`Neuer Eintrag`)}</h2>
                    <BlacklistForm submitError={formError} onSubmit={handleCreate} />
                </div>
            </div>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Blacklist konnte nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {data && !isLoading && !error ? (
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>{i18n._(t`E-Mail`)}</th>
                                <th>{i18n._(t`Domäne`)}</th>
                                <th>{i18n._(t`Notiz`)}</th>
                                <th>{i18n._(t`Erstellt`)}</th>
                                <th>{i18n._(t`Aktionen`)}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((entry) => (
                                <tr key={entry.id}>
                                    <td>{entry.email ?? '—'}</td>
                                    <td>{entry.domain ?? '—'}</td>
                                    <td>{entry.note ?? ''}</td>
                                    <td>{formatDateTime(entry.created_at, i18n)}</td>
                                    <td>
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-error btn-outline"
                                            onClick={() => void handleDelete(entry)}
                                        >
                                            {i18n._(t`Löschen`)}
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}

            {data && data.length === 0 && !isLoading && !error ? (
                <p className="text-base-content/70">{i18n._(t`Keine Blacklist-Einträge vorhanden.`)}</p>
            ) : null}
        </div>
    );
}

export function ApprovalsPage() {
    const { i18n } = useLingui();
    const [tab, setTab] = useState<ApprovalsTab>('applications');

    return (
        <section className="flex flex-col gap-6">
            <h1 className="text-3xl font-bold">{i18n._(t`Freigaben`)}</h1>
            <div role="tablist" className="tabs tabs-box">
                <button
                    role="tab"
                    className={`tab ${tab === 'applications' ? 'tab-active' : ''}`}
                    aria-selected={tab === 'applications'}
                    onClick={() => setTab('applications')}
                >
                    {i18n._(t`Anträge`)}
                </button>
                <button
                    role="tab"
                    className={`tab ${tab === 'subapplications' ? 'tab-active' : ''}`}
                    aria-selected={tab === 'subapplications'}
                    onClick={() => setTab('subapplications')}
                >
                    {i18n._(t`Sub-Anträge`)}
                </button>
                <button
                    role="tab"
                    className={`tab ${tab === 'blacklist' ? 'tab-active' : ''}`}
                    aria-selected={tab === 'blacklist'}
                    onClick={() => setTab('blacklist')}
                >
                    {i18n._(t`Blacklist`)}
                </button>
            </div>
            {tab === 'applications' ? <ApplicationsTab /> : null}
            {tab === 'subapplications' ? <SubApplicationsTab /> : null}
            {tab === 'blacklist' ? <BlacklistTab /> : null}
        </section>
    );
}
