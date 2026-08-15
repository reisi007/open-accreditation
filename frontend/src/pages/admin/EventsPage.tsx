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

export function EventsPage() {
    const { i18n } = useLingui();
    const { currentTeamIds } = useAdminTeams();
    const [activeFilter, setActiveFilter] = useState<ActiveFilter>('all');
    const [page, setPage] = useState(1);

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

    const totalCount = events?.length ?? 0;
    const pageCount = Math.max(1, Math.ceil(totalCount / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount);
    // Newest first (backend orders alphabetically, which would bury newly
    // created rows behind the 20-row page boundary and break the E2E flow).
    const orderedEvents = [...(events ?? [])].sort((a, b) => b.id - a.id);
    const pagedEvents = orderedEvents.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

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
                    <span>{i18n._(t`Events konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {listError ? (
                <div role="alert" className="alert alert-error">
                    <span>{listError}</span>
                </div>
            ) : null}

            {events && !isLoading && !error ? (
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <p aria-live="polite" className="text-sm text-base-content/70">
                            {totalCount === 1 ? '1 Event' : `${totalCount} Events`}
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
                                <div className="max-h-96 overflow-y-auto">
                                    <table className="table">
                                        <thead>
                                            <tr>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Titel`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Team`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Datum`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Frist`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Status`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Aktionen`)}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {pagedEvents.map((event) => (
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
                            </div>
                            <div className="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-r from-transparent to-base-100 lg:hidden"></div>
                        </div>
                        <MobileScrollHint />
                    </div>
                </div>
            ) : null}

            {events && events.length === 0 && !isLoading && !error ? (
                <div className="card border border-base-300 bg-base-100">
                    <div className="card-body items-center justify-center py-16 text-center">
                        <span className="iconify mdi--calendar-outline text-6xl text-base-content/40"></span>
                        <h2 className="card-title">{i18n._(t`Noch keine Events vorhanden.`)}</h2>
                        <p className="text-base-content/70">
                            {i18n._(t`Lege das erste Event an, damit Akkreditierungen einem Spiel zugeordnet werden können.`)}
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
