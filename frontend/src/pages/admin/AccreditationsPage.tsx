import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import useSWR from 'swr';
import {
    ApiError,
    createAccreditation,
    createSubAccreditation,
    deleteAccreditation,
    deleteSubAccreditation,
    listAdminAccreditations,
    listAdminSubAccreditations,
    updateAccreditation,
    updateSubAccreditation,
} from '../../api/client';
import type { Accreditation, SubAccreditation } from '../../api/types';
import { accreditationScopeLabel, subTypeLabel } from '../../logic/accreditationLabels';
import { useAdminTeams } from '../../logic/useAdminTeams';
import { AccreditationForm } from './AccreditationForm';
import { buildAccreditationPayload, type AccreditationFormValues } from './accreditationFormUtils';
import { SubAccreditationForm } from './SubAccreditationForm';
import { buildSubAccreditationPayload, type SubAccreditationFormValues } from './accreditationSubFormUtils';

type ActiveFilter = 'all' | 'active' | 'inactive';

const PAGE_SIZE = 20;

/**
 * Wide tables scroll horizontally by design. On mobile there is no native
 * scroll affordance, so a subtle right-edge fade (over the container) plus a
 * one-line hint shows that more columns are reachable by swiping. Desktop
 * keeps the default scrollbar.
 */
function MobileScrollHint() {
    const { i18n } = useLingui();

    return (
        <p className="mt-2 flex items-center gap-1 text-sm text-base-content/60 lg:hidden">
            <span className="iconify mdi--gesture-swipe-horizontal text-lg"></span>
            {i18n._(t`Zum Scrollen wischen`)}
        </p>
    );
}

export function AccreditationsPage() {
    const { i18n } = useLingui();
    const { currentTeamIds } = useAdminTeams();
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>('all');
    const [page, setPage] = useState(1);

    const { data, error, isLoading, mutate } = useSWR<Accreditation[]>(['/api/admin/accreditations', activeFilter], () =>
        listAdminAccreditations(activeFilter === 'all' ? undefined : { active: activeFilter === 'active' }),
    );

    const [showForm, setShowForm] = useState(false);
    const [formAccreditation, setFormAccreditation] = useState<Accreditation | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [listError, setListError] = useState<string | null>(null);

    const [subAccreditation, setSubAccreditation] = useState<Accreditation | null>(null);
    const [showSubForm, setShowSubForm] = useState(false);
    const [subFormItem, setSubFormItem] = useState<SubAccreditation | null>(null);
    const [subFormError, setSubFormError] = useState<string | null>(null);
    const [subListError, setSubListError] = useState<string | null>(null);

    const {
        data: subs,
        error: subsError,
        isLoading: subsLoading,
        mutate: mutateSubs,
    } = useSWR<SubAccreditation[]>(
        subAccreditation ? ['/api/admin/accreditations', subAccreditation.id, 'sub-accreditations'] : null,
        () => (subAccreditation ? listAdminSubAccreditations(subAccreditation.id) : Promise.resolve([])),
    );

    const isTeamScoped = currentTeamIds.length > 0;
    const isReadOnly = (accreditation: Accreditation) =>
        isTeamScoped && (accreditation.team_id === null || !currentTeamIds.includes(accreditation.team_id));

    const totalCount = data?.length ?? 0;
    const pageCount = Math.max(1, Math.ceil(totalCount / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount);
    // Newest first (backend orders by ascending id, which would bury newly
    // created rows behind the 20-row page boundary and break the E2E flow).
    const orderedAccreditations = [...(data ?? [])].sort((a, b) => b.id - a.id);
    const pagedAccreditations = orderedAccreditations.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

    const openNew = () => {
        setFormAccreditation(null);
        setFormError(null);
        setListError(null);
        setShowForm(true);
    };

    const openEdit = (accreditation: Accreditation) => {
        setFormAccreditation(accreditation);
        setFormError(null);
        setListError(null);
        setShowForm(true);
    };

    const closeForm = () => {
        setShowForm(false);
        setFormAccreditation(null);
        setFormError(null);
    };

    const handleSave = async (values: AccreditationFormValues) => {
        setFormError(null);
        try {
            const teamAdminTeamId = currentTeamIds.length > 0 ? currentTeamIds[0] : null;
            if (formAccreditation) {
                await updateAccreditation(formAccreditation.id, buildAccreditationPayload(values, teamAdminTeamId));
            } else {
                await createAccreditation(buildAccreditationPayload(values, teamAdminTeamId));
            }
            await mutate();
            closeForm();
        } catch (err) {
            setFormError(
                err instanceof ApiError ? err.message : i18n._(t`Akkreditierung konnte nicht gespeichert werden.`),
            );
        }
    };

    const handleDelete = async (accreditation: Accreditation) => {
        if (!window.confirm(i18n._(t`Akkreditierung wirklich löschen?`))) return;
        setListError(null);
        try {
            await deleteAccreditation(accreditation.id);
            await mutate();
        } catch (err) {
            setListError(
                err instanceof ApiError ? err.message : i18n._(t`Akkreditierung konnte nicht gelöscht werden.`),
            );
        }
    };

    const openSubs = (accreditation: Accreditation) => {
        setSubAccreditation(accreditation);
        setShowSubForm(false);
        setSubFormItem(null);
        setSubFormError(null);
        setSubListError(null);
    };

    const closeSubs = () => {
        setSubAccreditation(null);
        setShowSubForm(false);
        setSubFormItem(null);
        setSubFormError(null);
    };

    const openSubForm = (item: SubAccreditation | null) => {
        setSubFormItem(item);
        setSubFormError(null);
        setShowSubForm(true);
    };

    const closeSubForm = () => {
        setShowSubForm(false);
        setSubFormItem(null);
        setSubFormError(null);
    };

    const handleSubSave = async (values: SubAccreditationFormValues) => {
        setSubFormError(null);
        try {
            if (subFormItem) {
                await updateSubAccreditation(subFormItem.id, buildSubAccreditationPayload(values));
            } else if (subAccreditation) {
                await createSubAccreditation(subAccreditation.id, buildSubAccreditationPayload(values));
            }
            await mutateSubs();
            closeSubForm();
        } catch (err) {
            setSubFormError(
                err instanceof ApiError ? err.message : i18n._(t`Sub-Akkreditierung konnte nicht gespeichert werden.`),
            );
        }
    };

    const handleSubDelete = async (sub: SubAccreditation) => {
        if (!window.confirm(i18n._(t`Sub-Akkreditierung wirklich löschen?`))) return;
        setSubListError(null);
        try {
            await deleteSubAccreditation(sub.id);
            await mutateSubs();
        } catch (err) {
            setSubListError(
                err instanceof ApiError ? err.message : i18n._(t`Sub-Akkreditierung konnte nicht gelöscht werden.`),
            );
        }
    };

    const formatDeadline = (accreditation: Accreditation) => {
        if (accreditation.deadline_start && accreditation.deadline_end) {
            return `${accreditation.deadline_start} – ${accreditation.deadline_end}`;
        }
        return accreditation.deadline_start ?? accreditation.deadline_end ?? '';
    };

    const formatSubDeadline = (sub: SubAccreditation) => {
        if (sub.deadline_start && sub.deadline_end) {
            return `${sub.deadline_start} – ${sub.deadline_end}`;
        }
        return sub.deadline_start ?? sub.deadline_end ?? '';
    };

    return (
        <section className="flex flex-col gap-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-3xl font-bold">{i18n._(t`Akkreditierungen`)}</h1>
                <div className="flex flex-wrap items-center gap-2">
                    <select
                        aria-label={i18n._(t`Status`)}
                        className="select select-sm"
                        value={activeFilter}
                        onChange={(event) => {
                            setActiveFilter(event.target.value as ActiveFilter);
                            setPage(1);
                        }}
                    >
                        <option value="all">{i18n._(t`Alle`)}</option>
                        <option value="active">{i18n._(t`Aktiv`)}</option>
                        <option value="inactive">{i18n._(t`Inaktiv`)}</option>
                    </select>
                    <button type="button" className="btn btn-primary" onClick={openNew}>
                        <span className="iconify mdi--plus text-xl"></span>
                        {i18n._(t`Neu`)}
                    </button>
                </div>
            </div>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Akkreditierungen konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {listError ? (
                <div role="alert" className="alert alert-error">
                    <span>{listError}</span>
                </div>
            ) : null}

            {data && !isLoading && !error ? (
                <div className="flex flex-col gap-2">
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
                    <div className="flex flex-col">
                        <div className="relative">
                            <div className="overflow-x-auto">
                                <table className="table">
                                        <thead>
                                            <tr>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Kategorie`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Geltungsbereich`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Event / Team`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Quota`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Verfügbar`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Frist`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Status`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Aktionen`)}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {pagedAccreditations.map((accreditation) => (
                                                <tr key={accreditation.id}>
                                                    <td className="font-medium">{accreditation.category?.name ?? ''}</td>
                                                    <td>{accreditationScopeLabel(accreditation.scope, i18n)}</td>
                                                    <td className="min-w-0">
                                                        <div className="flex flex-wrap gap-1">
                                                            {accreditation.event ? (
                                                                <span className="badge badge-info badge-sm min-w-0 max-w-48">
                                                                    <span className="truncate" title={accreditation.event.title}>
                                                                        {accreditation.event.title}
                                                                    </span>
                                                                </span>
                                                            ) : null}
                                                            {accreditation.team ? (
                                                                <span className="badge badge-outline badge-sm min-w-0 max-w-40">
                                                                    <span className="truncate" title={accreditation.team.name}>
                                                                        {accreditation.team.name}
                                                                    </span>
                                                                </span>
                                                            ) : (
                                                                <span className="badge badge-ghost badge-sm">
                                                                    {i18n._(t`Verbandsebene`)}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td>{accreditation.quota}</td>
                                                    <td>
                                                        <span
                                                            className={`badge badge-sm ${
                                                                accreditation.available > 0 ? 'badge-success' : 'badge-warning'
                                                            }`}
                                                        >
                                                            {accreditation.available}
                                                        </span>
                                                    </td>
                                                    <td>{formatDeadline(accreditation)}</td>
                                                    <td>
                                                        {accreditation.active ? (
                                                            <span className="badge badge-success badge-sm">{i18n._(t`Aktiv`)}</span>
                                                        ) : (
                                                            <span className="badge badge-ghost badge-sm">{i18n._(t`Inaktiv`)}</span>
                                                        )}
                                                    </td>
                                                    <td>
                                                        {isReadOnly(accreditation) ? null : (
                                                            <div className="flex gap-2">
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-sm btn-outline"
                                                                    onClick={() => openSubs(accreditation)}
                                                                >
                                                                    {i18n._(t`Sub-Akkreditierungen`)}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-sm btn-outline"
                                                                    onClick={() => openEdit(accreditation)}
                                                                >
                                                                    {i18n._(t`Bearbeiten`)}
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-sm btn-error btn-outline"
                                                                    onClick={() => void handleDelete(accreditation)}
                                                                >
                                                                    {i18n._(t`Löschen`)}
                                                                </button>
                                                            </div>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                            </div>
                            <div className="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-r from-transparent to-base-100 lg:hidden"></div>
                        </div>
                        <MobileScrollHint />
                    </div>
                </div>
            ) : null}

            {data && data.length === 0 && !isLoading && !error ? (
                <div className="card border border-base-300 bg-base-100">
                    <div className="card-body items-center justify-center py-16 text-center">
                        <span className="iconify mdi--badge-account-outline text-6xl text-base-content/40"></span>
                        <h2 className="card-title">{i18n._(t`Noch keine Akkreditierungen vorhanden.`)}</h2>
                        <p className="text-base-content/70">
                            {i18n._(t`Lege die erste Akkreditierung an, damit sich Interessierte bewerben können.`)}
                        </p>
                        <button type="button" className="btn btn-primary mt-2" onClick={openNew}>
                            <span className="iconify mdi--plus text-xl"></span>
                            {i18n._(t`Neu`)}
                        </button>
                    </div>
                </div>
            ) : null}

            {showForm ? (
                <dialog className="modal modal-open">
                    <div className="modal-box">
                        <h3 className="text-lg font-bold">
                            {formAccreditation ? i18n._(t`Akkreditierung bearbeiten`) : i18n._(t`Neue Akkreditierung`)}
                        </h3>
                        <div className="mt-4">
                            <AccreditationForm
                                initial={formAccreditation}
                                submitLabel={formAccreditation ? i18n._(t`Speichern`) : i18n._(t`Akkreditierung erstellen`)}
                                submitError={formError}
                                onSubmit={handleSave}
                                onCancel={closeForm}
                            />
                        </div>
                    </div>
                    <form method="dialog" className="modal-backdrop">
                        <button type="button" onClick={closeForm}>
                            {i18n._(t`Schließen`)}
                        </button>
                    </form>
                </dialog>
            ) : null}

            {subAccreditation ? (
                <dialog className="modal modal-open">
                    <div className="modal-box">
                        <div className="flex items-center justify-between gap-2">
                            <h3 className="text-lg font-bold">{i18n._(t`Sub-Akkreditierungen`)}</h3>
                            <button type="button" className="btn btn-sm btn-primary" onClick={() => openSubForm(null)}>
                                <span className="iconify mdi--plus text-xl"></span>
                                {i18n._(t`Neu`)}
                            </button>
                        </div>

                        {subsLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

                        {subsError ? (
                            <div role="alert" className="alert alert-error mt-2">
                                <span>{i18n._(t`Sub-Akkreditierungen konnten nicht geladen werden.`)}</span>
                            </div>
                        ) : null}

                        {subListError ? (
                            <div role="alert" className="alert alert-error mt-2">
                                <span>{subListError}</span>
                            </div>
                        ) : null}

                        {subs && subs.length === 0 && !subsLoading && !subsError ? (
                            <p className="mt-4 text-base-content/70">
                                {i18n._(t`Noch keine Sub-Akkreditierungen vorhanden.`)}
                            </p>
                        ) : null}

                        {subs && subs.length > 0 && !subsLoading && !subsError ? (
                            <div className="mt-4 flex flex-col gap-2">
                                {subs.map((sub) => (
                                    <article
                                        key={sub.id}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-base-300 p-3"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="badge badge-outline badge-sm">{subTypeLabel(sub.type, i18n)}</span>
                                            <span
                                                className={`badge badge-sm ${
                                                    sub.available > 0 ? 'badge-success' : 'badge-warning'
                                                }`}
                                            >
                                                {i18n._(t`Quota`)} {sub.quota} · {i18n._(t`Verfügbar`)} {sub.available}
                                            </span>
                                            <span className="badge badge-warning badge-sm">{formatSubDeadline(sub)}</span>
                                            {sub.auto_approve ? (
                                                <span className="badge badge-info badge-sm">
                                                    {i18n._(t`Automatische Freigabe`)}
                                                </span>
                                            ) : null}
                                            {sub.active ? (
                                                <span className="badge badge-success badge-sm">{i18n._(t`Aktiv`)}</span>
                                            ) : (
                                                <span className="badge badge-ghost badge-sm">{i18n._(t`Inaktiv`)}</span>
                                            )}
                                        </div>
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline"
                                                onClick={() => openSubForm(sub)}
                                            >
                                                {i18n._(t`Bearbeiten`)}
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-error btn-outline"
                                                onClick={() => void handleSubDelete(sub)}
                                            >
                                                {i18n._(t`Löschen`)}
                                            </button>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        ) : null}

                        <div className="mt-4 flex justify-end">
                            <button type="button" className="btn" onClick={closeSubs}>
                                {i18n._(t`Schließen`)}
                            </button>
                        </div>
                    </div>
                    <form method="dialog" className="modal-backdrop">
                        <button type="button" onClick={closeSubs}>
                            {i18n._(t`Schließen`)}
                        </button>
                    </form>
                </dialog>
            ) : null}

            {showSubForm && subAccreditation ? (
                <dialog className="modal modal-open">
                    <div className="modal-box">
                        <h3 className="text-lg font-bold">
                            {subFormItem ? i18n._(t`Sub-Akkreditierung bearbeiten`) : i18n._(t`Neue Sub-Akkreditierung`)}
                        </h3>
                        <div className="mt-4">
                            <SubAccreditationForm
                                initial={subFormItem}
                                submitLabel={
                                    subFormItem ? i18n._(t`Speichern`) : i18n._(t`Sub-Akkreditierung erstellen`)
                                }
                                submitError={subFormError}
                                onSubmit={handleSubSave}
                                onCancel={closeSubForm}
                            />
                        </div>
                    </div>
                    <form method="dialog" className="modal-backdrop">
                        <button type="button" onClick={closeSubForm}>
                            {i18n._(t`Schließen`)}
                        </button>
                    </form>
                </dialog>
            ) : null}
        </section>
    );
}
