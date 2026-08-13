import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import useSWR from 'swr';
import {
    ApiError,
    createAccreditation,
    deleteAccreditation,
    listAdminAccreditations,
    updateAccreditation,
} from '../../api/client';
import type { Accreditation } from '../../api/types';
import { accreditationScopeLabel } from '../../logic/accreditationLabels';
import { useAdminTeams } from '../../logic/useAdminTeams';
import { AccreditationForm } from './AccreditationForm';
import { buildAccreditationPayload, type AccreditationFormValues } from './accreditationFormUtils';

type ActiveFilter = 'all' | 'active' | 'inactive';

export function AccreditationsPage() {
    const { i18n } = useLingui();
    const { currentTeamIds } = useAdminTeams();
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>('all');

    const { data, error, isLoading, mutate } = useSWR<Accreditation[]>(['/api/admin/accreditations', activeFilter], () =>
        listAdminAccreditations(activeFilter === 'all' ? undefined : { active: activeFilter === 'active' }),
    );

    const [showForm, setShowForm] = useState(false);
    const [formAccreditation, setFormAccreditation] = useState<Accreditation | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [listError, setListError] = useState<string | null>(null);

    const isTeamScoped = currentTeamIds.length > 0;
    const isReadOnly = (accreditation: Accreditation) =>
        isTeamScoped && (accreditation.team_id === null || !currentTeamIds.includes(accreditation.team_id));

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

    const formatDeadline = (accreditation: Accreditation) => {
        if (accreditation.deadline_start && accreditation.deadline_end) {
            return `${accreditation.deadline_start} – ${accreditation.deadline_end}`;
        }
        return accreditation.deadline_start ?? accreditation.deadline_end ?? '';
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
                        onChange={(event) => setActiveFilter(event.target.value as ActiveFilter)}
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
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>{i18n._(t`Kategorie`)}</th>
                                <th>{i18n._(t`Geltungsbereich`)}</th>
                                <th>{i18n._(t`Event / Team`)}</th>
                                <th>{i18n._(t`Quota`)}</th>
                                <th>{i18n._(t`Verfügbar`)}</th>
                                <th>{i18n._(t`Frist`)}</th>
                                <th>{i18n._(t`Status`)}</th>
                                <th>{i18n._(t`Aktionen`)}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((accreditation) => (
                                <tr key={accreditation.id}>
                                    <td className="font-medium">{accreditation.category?.name ?? ''}</td>
                                    <td>{accreditationScopeLabel(accreditation.scope, i18n)}</td>
                                    <td>
                                        <div className="flex flex-wrap gap-1">
                                            {accreditation.event ? (
                                                <span className="badge badge-info badge-sm">{accreditation.event.title}</span>
                                            ) : null}
                                            {accreditation.team ? (
                                                <span className="badge badge-outline badge-sm">{accreditation.team.name}</span>
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
            ) : null}

            {data && data.length === 0 && !isLoading && !error ? (
                <p className="text-base-content/70">{i18n._(t`Noch keine Akkreditierungen vorhanden.`)}</p>
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
        </section>
    );
}
