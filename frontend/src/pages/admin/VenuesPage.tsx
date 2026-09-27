import { msg, t } from '@lingui/core/macro';
import { useLingui } from '@lingui/react';
import { useState } from 'react';
import { ApiError, createVenue, deleteVenue, updateVenue } from '../../api/client';
import type { Venue } from '../../api/types';
import { Modal } from '../../components/Modal';
import { useVenues } from '../../logic/useVenues';
import { VenueForm } from './VenueForm';
import type { VenueFormValues } from './venueFormUtils';

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

export function VenuesPage() {
    const { i18n } = useLingui();
    const { venues, error, isLoading, mutate } = useVenues();

    const [page, setPage] = useState(1);
    const [showForm, setShowForm] = useState(false);
    const [formVenue, setFormVenue] = useState<Venue | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [listError, setListError] = useState<string | null>(null);
    const [pendingId, setPendingId] = useState<number | null>(null);

    const totalCount = venues?.length ?? 0;
    const pageCount = Math.max(1, Math.ceil(totalCount / PAGE_SIZE));
    const currentPage = Math.min(page, pageCount);
    // Newest first: the backend orders alphabetically, which would bury a
    // freshly created venue behind the page boundary.
    const orderedVenues = [...(venues ?? [])].sort((a, b) => b.id - a.id);
    const pagedVenues = orderedVenues.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE);

    const openNew = () => {
        setFormVenue(null);
        setFormError(null);
        setListError(null);
        setShowForm(true);
    };

    const openEdit = (venue: Venue) => {
        setFormVenue(venue);
        setFormError(null);
        setListError(null);
        setShowForm(true);
    };

    const closeForm = () => {
        setShowForm(false);
        setFormVenue(null);
        setFormError(null);
    };

    const handleSave = async (values: VenueFormValues) => {
        setFormError(null);
        try {
            if (formVenue) {
                await updateVenue(formVenue.id, { name: values.name.trim() });
            } else {
                await createVenue({ name: values.name.trim() });
            }
            await mutate();
            closeForm();
        } catch (err) {
            // A duplicate (422) carries the backend's German message — showing
            // it verbatim beats inventing a second wording for the same case.
            setFormError(err instanceof ApiError ? err.message : i18n._(t`Spielort konnte nicht gespeichert werden.`));
        }
    };

    /**
     * Deactivate / reactivate. This is the PRIMARY action: a referenced venue
     * must not be deletable, and deactivation is reversible, so it is the way
     * to retire a venue that is still in use by teams or events.
     */
    const handleToggleActive = async (venue: Venue) => {
        setListError(null);
        setPendingId(venue.id);
        try {
            await updateVenue(venue.id, { is_active: !venue.is_active });
            await mutate();
        } catch (err) {
            setListError(
                err instanceof ApiError ? err.message : i18n._(t`Spielort konnte nicht gespeichert werden.`),
            );
        } finally {
            setPendingId(null);
        }
    };

    /**
     * Delete. The escape hatch for unreferenced rows; a referenced venue comes
     * back as a 409 whose German message (with the reference counts) is shown
     * verbatim in the list alert.
     */
    const handleDelete = async (venue: Venue) => {
        if (!window.confirm(i18n._(t`Spielort wirklich löschen?`))) return;
        setListError(null);
        setPendingId(venue.id);
        try {
            await deleteVenue(venue.id);
            await mutate();
        } catch (err) {
            setListError(err instanceof ApiError ? err.message : i18n._(t`Spielort konnte nicht gelöscht werden.`));
        } finally {
            setPendingId(null);
        }
    };

    const references = (venue: Venue) => venue.teams_count + venue.events_count;

    return (
        <section className="flex flex-col gap-6">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-bold">{i18n._(t`Spielorte`)}</h1>
                    <p className="text-sm text-base-content/70">
                        {i18n._(t`Stammdaten der Spielorte. Wird von Teams und Events referenziert.`)}
                    </p>
                </div>
                <button type="button" className="btn btn-primary" onClick={openNew}>
                    <span className="iconify mdi--plus text-xl"></span>
                    {i18n._(t`Neu`)}
                </button>
            </div>

            {isLoading ? <span className="loading loading-spinner loading-lg"></span> : null}

            {error ? (
                <div role="alert" className="alert alert-error">
                    <span>{i18n._(t`Spielorte konnten nicht geladen werden.`)}</span>
                </div>
            ) : null}

            {listError ? (
                <div role="alert" className="alert alert-error">
                    <span>{listError}</span>
                </div>
            ) : null}

            {venues && !isLoading && !error && totalCount > 0 ? (
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <p aria-live="polite" className="text-sm text-base-content/70">
                            {i18n._({
                                ...msg`{totalCount, plural, one {# Spielort} other {# Spielorte}}`,
                                values: { totalCount },
                            })}
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
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Name`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Status`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100">{i18n._(t`Referenzen`)}</th>
                                                <th className="sticky top-0 z-10 bg-base-100 min-w-40">{i18n._(t`Aktionen`)}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {pagedVenues.map((venue) => (
                                                <tr key={venue.id}>
                                                    <td className="font-medium">{venue.name}</td>
                                                    <td>
                                                        {venue.is_active ? (
                                                            <span className="badge badge-success badge-sm">
                                                                {i18n._(t`Aktiv`)}
                                                            </span>
                                                        ) : (
                                                            <span className="badge badge-ghost badge-sm">
                                                                {i18n._(t`Inaktiv`)}
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td>
                                                        {references(venue) === 0 ? (
                                                            <span className="text-base-content/60">
                                                                {i18n._(t`Keine`)}
                                                            </span>
                                                        ) : (
                                                            <span className="flex flex-wrap gap-1">
                                                                <span className="badge badge-outline badge-sm">
                                                                    {i18n._({
                                                                        ...msg`{teamsCount, plural, one {# Team} other {# Teams}}`,
                                                                        values: { teamsCount: venue.teams_count },
                                                                    })}
                                                                </span>
                                                                <span className="badge badge-outline badge-sm">
                                                                    {i18n._({
                                                                        ...msg`{eventsCount, plural, one {# Event} other {# Events}}`,
                                                                        values: { eventsCount: venue.events_count },
                                                                    })}
                                                                </span>
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td>
                                                        <div className="flex flex-wrap gap-2">
                                                            <button
                                                                type="button"
                                                                className="btn btn-sm btn-outline"
                                                                onClick={() => openEdit(venue)}
                                                            >
                                                                {i18n._(t`Umbenennen`)}
                                                            </button>
                                                            {/*
                                                              The primary action for a REFERENCED
                                                              venue: delete is refused with a
                                                              409, deactivate is not.
                                                            */}
                                                            <button
                                                                type="button"
                                                                className="btn btn-sm btn-outline"
                                                                disabled={pendingId === venue.id}
                                                                onClick={() => void handleToggleActive(venue)}
                                                            >
                                                                {venue.is_active ? i18n._(t`Deaktivieren`) : i18n._(t`Reaktivieren`)}
                                                            </button>
                                                            {references(venue) === 0 ? (
                                                                <button
                                                                    type="button"
                                                                    className="btn btn-sm btn-error btn-outline"
                                                                    disabled={pendingId === venue.id}
                                                                    onClick={() => void handleDelete(venue)}
                                                                >
                                                                    {i18n._(t`Löschen`)}
                                                                </button>
                                                            ) : null}
                                                        </div>
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

            {venues && totalCount === 0 && !isLoading && !error ? (
                <div className="card border border-base-300 bg-base-100">
                    <div className="card-body items-center justify-center py-16 text-center">
                        <span className="iconify mdi--map-marker-outline text-6xl text-base-content/40"></span>
                        <h2 className="card-title">{i18n._(t`Noch keine Spielorte vorhanden.`)}</h2>
                        <p className="text-base-content/70">
                            {i18n._(t`Lege den ersten Spielort an. Er kann direkt in Team- und Event-Formularen angelegt werden.`)}
                        </p>
                        <button type="button" className="btn btn-primary mt-2" onClick={openNew}>
                            <span className="iconify mdi--plus text-xl"></span>
                            {i18n._(t`Neu`)}
                        </button>
                    </div>
                </div>
            ) : null}

            {showForm ? (
                <Modal onClose={closeForm}>
                    <h3 className="text-lg font-bold">
                        {formVenue ? i18n._(t`Spielort umbenennen`) : i18n._(t`Neuer Spielort`)}
                    </h3>
                    <div className="mt-4">
                        <VenueForm
                            initial={formVenue}
                            submitLabel={formVenue ? i18n._(t`Speichern`) : i18n._(t`Spielort erstellen`)}
                            submitError={formError}
                            onSubmit={handleSave}
                            onCancel={closeForm}
                        />
                    </div>
                </Modal>
            ) : null}
        </section>
    );
}
