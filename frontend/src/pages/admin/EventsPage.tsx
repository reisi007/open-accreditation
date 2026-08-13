import { t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import useSWR from 'swr';
import { ApiError, createEvent, deleteEvent, listEvents, updateEvent } from '../../api/client';
import type { Event } from '../../api/types';
import { useAdminTeams } from '../../logic/useAdminTeams';
import { EventForm } from './EventForm';
import { buildEventPayload, type EventFormValues } from './eventFormUtils';

type ActiveFilter = 'all' | 'active' | 'inactive';

export function EventsPage() {
    const { i18n } = useLingui();
    const { currentTeamIds } = useAdminTeams();
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>('all');

    const eventsKey = ['/api/admin/events', activeFilter];
    const { data: events, error, isLoading, mutate } = useSWR<Event[]>(eventsKey, () =>
        listEvents(activeFilter === 'all' ? undefined : { active: activeFilter === 'active' }),
    );

    const [showForm, setShowForm] = useState(false);
    const [formEvent, setFormEvent] = useState<Event | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [listError, setListError] = useState<string | null>(null);

    const openNew = () => {
        setFormEvent(null);
        setFormError(null);
        setListError(null);
        setShowForm(true);
    };

    const openEdit = (event: Event) => {
        setFormEvent(event);
        setFormError(null);
        setListError(null);
        setShowForm(true);
    };

    const closeForm = () => {
        setShowForm(false);
        setFormEvent(null);
        setFormError(null);
    };

    const handleSave = async (values: EventFormValues) => {
        setFormError(null);
        try {
            if (formEvent) {
                await updateEvent(formEvent.id, buildEventPayload(values));
            } else {
                await createEvent(buildEventPayload(values));
            }
            await mutate();
            closeForm();
        } catch (err) {
            setFormError(err instanceof ApiError ? err.message : i18n._(t`Event konnte nicht gespeichert werden.`));
        }
    };

    const handleDelete = async (event: Event) => {
        if (!window.confirm(i18n._(t`Event wirklich löschen?`))) return;
        setListError(null);
        try {
            await deleteEvent(event.id);
            await mutate();
        } catch (err) {
            setListError(err instanceof ApiError ? err.message : i18n._(t`Event konnte nicht gelöscht werden.`));
        }
    };

    const isTeamScoped = currentTeamIds.length > 0;
    const isReadOnly = (event: Event) => isTeamScoped && (event.team_id === null || !currentTeamIds.includes(event.team_id));

    const formatDeadline = (event: Event) => {
        if (event.deadline_start && event.deadline_end) {
            return `${event.deadline_start} – ${event.deadline_end}`;
        }
        return event.deadline_start ?? event.deadline_end ?? '';
    };

    return (
        <section className="flex flex-col gap-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <h1 className="text-3xl font-bold">{i18n._(t`Events`)}</h1>
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
                    <span>{i18n._(t`Events konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {listError ? (
                <div role="alert" className="alert alert-error">
                    <span>{listError}</span>
                </div>
            ) : null}

            {events && !isLoading && !error ? (
                <div className="overflow-x-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>{i18n._(t`Titel`)}</th>
                                <th>{i18n._(t`Team`)}</th>
                                <th>{i18n._(t`Datum`)}</th>
                                <th>{i18n._(t`Frist`)}</th>
                                <th>{i18n._(t`Status`)}</th>
                                <th>{i18n._(t`Aktionen`)}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.map((event) => (
                                <tr key={event.id}>
                                    <td className="font-medium">{event.title}</td>
                                    <td>
                                        {event.team ? (
                                            <span className="badge badge-outline badge-sm">{event.team.name}</span>
                                        ) : (
                                            <span className="badge badge-ghost badge-sm">{i18n._(t`Verbandsebene`)}</span>
                                        )}
                                    </td>
                                    <td>{event.date ?? ''}</td>
                                    <td>{formatDeadline(event)}</td>
                                    <td>
                                        {event.active ? (
                                            <span className="badge badge-success badge-sm">{i18n._(t`Aktiv`)}</span>
                                        ) : (
                                            <span className="badge badge-ghost badge-sm">{i18n._(t`Inaktiv`)}</span>
                                        )}
                                    </td>
                                    <td>
                                        {isReadOnly(event) ? null : (
                                            <div className="flex gap-2">
                                                <button
                                                    type="button"
                                                    className="btn btn-sm btn-outline"
                                                    onClick={() => openEdit(event)}
                                                >
                                                    {i18n._(t`Bearbeiten`)}
                                                </button>
                                                <button
                                                    type="button"
                                                    className="btn btn-sm btn-error btn-outline"
                                                    onClick={() => void handleDelete(event)}
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

            {events && events.length === 0 && !isLoading && !error ? (
                <p className="text-base-content/70">{i18n._(t`Noch keine Events vorhanden.`)}</p>
            ) : null}

            {showForm ? (
                <dialog className="modal modal-open">
                    <div className="modal-box">
                        <h3 className="text-lg font-bold">
                            {formEvent ? i18n._(t`Event bearbeiten`) : i18n._(t`Neues Event`)}
                        </h3>
                        <div className="mt-4">
                            <EventForm
                                initial={formEvent}
                                submitLabel={formEvent ? i18n._(t`Speichern`) : i18n._(t`Event erstellen`)}
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
