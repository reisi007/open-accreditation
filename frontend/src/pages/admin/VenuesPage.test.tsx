import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SWRConfig } from 'swr';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/client';
import type { Venue } from '../../api/types';
import { renderWithProviders } from '../../test-setup';
import { VenuesPage } from './VenuesPage';

const { listVenuesMock, createVenueMock, updateVenueMock, deleteVenueMock, venueStore } = vi.hoisted(() => {
    const venueStore: Venue[] = [];
    return {
        venueStore,
        listVenuesMock: vi.fn(async () => venueStore.map((venue) => ({ ...venue }))),
        createVenueMock: vi.fn(),
        updateVenueMock: vi.fn(),
        deleteVenueMock: vi.fn(),
    };
});

vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return {
        ...actual,
        listVenues: listVenuesMock,
        createVenue: createVenueMock,
        updateVenue: updateVenueMock,
        deleteVenue: deleteVenueMock,
    };
});

function makeVenue(overrides: Partial<Venue> & { id: number; name: string }): Venue {
    return {
        is_active: true,
        teams_count: 0,
        events_count: 0,
        created_at: '2026-09-01T10:00:00Z',
        updated_at: '2026-09-01T10:00:00Z',
        ...overrides,
    };
}

function setVenues(venues: Venue[]): void {
    venueStore.splice(0, venueStore.length, ...venues);
}

function renderPage() {
    return renderWithProviders(
        <SWRConfig value={{ provider: () => new Map() }}>
            <VenuesPage />
        </SWRConfig>,
    );
}

function row(name: string): HTMLElement {
    return screen.getByRole('row', { name: new RegExp(name) });
}

beforeEach(() => {
    setVenues([]);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.clearAllMocks();
});

describe('VenuesPage', () => {
    it('shows the count and the per-venue reference counts', async () => {
        setVenues([
            makeVenue({ id: 1, name: 'Arena Nord', teams_count: 1, events_count: 2 }),
            makeVenue({ id: 2, name: 'Arena Süd' }),
        ]);
        renderPage();

        expect(await screen.findByText('2 Spielorte')).toBeInTheDocument();
        expect(within(row('Arena Nord')).getByText('1 Team')).toBeInTheDocument();
        expect(within(row('Arena Nord')).getByText('2 Events')).toBeInTheDocument();
        // An unreferenced venue is explicit about having no references, so the
        // admin can tell "0" from "not loaded yet".
        expect(within(row('Arena Süd')).getByText('Keine')).toBeInTheDocument();
    });

    it('offers delete ONLY for an unreferenced venue, and deactivate for the referenced one', async () => {
        setVenues([
            makeVenue({ id: 1, name: 'Arena Nord', teams_count: 3, events_count: 0 }),
            makeVenue({ id: 2, name: 'Arena Süd' }),
        ]);
        renderPage();

        await screen.findByText('2 Spielorte');

        // Referenced: the primary action is deactivate, and there is no delete.
        expect(within(row('Arena Nord')).getByRole('button', { name: 'Deaktivieren' })).toBeEnabled();
        expect(within(row('Arena Nord')).queryByRole('button', { name: 'Löschen' })).not.toBeInTheDocument();

        // Unreferenced: both are available.
        expect(within(row('Arena Süd')).getByRole('button', { name: 'Deaktivieren' })).toBeEnabled();
        expect(within(row('Arena Süd')).getByRole('button', { name: 'Löschen' })).toBeEnabled();
    });

    it('deactivates a referenced venue through PUT and flips the badge', async () => {
        setVenues([makeVenue({ id: 1, name: 'Arena Nord', teams_count: 3, events_count: 4 })]);
        updateVenueMock.mockImplementation(async (id: number, payload: { is_active?: boolean }) => {
            const next = makeVenue({ id, name: 'Arena Nord', is_active: payload.is_active ?? true });
            setVenues([next]);
            return next;
        });
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Spielort');
        await user.click(within(row('Arena Nord')).getByRole('button', { name: 'Deaktivieren' }));

        await waitFor(() => expect(updateVenueMock).toHaveBeenCalledWith(1, { is_active: false }));
        expect(await within(row('Arena Nord')).findByText('Inaktiv')).toBeInTheDocument();
        // And the affordance flips with it.
        expect(within(row('Arena Nord')).getByRole('button', { name: 'Reaktivieren' })).toBeEnabled();
    });

    it('shows the German 409 message verbatim when a venue turns out to be referenced', async () => {
        // The reference counts on the page are a snapshot. A team or event can
        // reference the venue AFTER the list was read, so the server is the
        // authority and its 409 message must reach the admin verbatim.
        setVenues([makeVenue({ id: 2, name: 'Arena Süd' })]);
        deleteVenueMock.mockRejectedValue(
            new ApiError(409, 'Der Spielort wird noch von 2 Teams und 5 Events genutzt.', {}),
        );
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Spielort');
        await user.click(within(row('Arena Süd')).getByRole('button', { name: 'Löschen' }));

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Der Spielort wird noch von 2 Teams und 5 Events genutzt.',
        );
        // Nothing was removed locally — the API refused.
        expect(screen.getByRole('row', { name: new RegExp('Arena Süd') })).toBeInTheDocument();
    });

    it('deletes an unreferenced venue after the confirm dialog', async () => {
        setVenues([makeVenue({ id: 2, name: 'Arena Süd' })]);
        deleteVenueMock.mockImplementation(async (id: number) => {
            setVenues(venueStore.filter((venue) => venue.id !== id));
        });
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Spielort');
        await user.click(within(row('Arena Süd')).getByRole('button', { name: 'Löschen' }));

        expect(window.confirm).toHaveBeenCalledWith('Spielort wirklich löschen?');
        await waitFor(() => expect(deleteVenueMock).toHaveBeenCalledWith(2));
        expect(await screen.findByText('Noch keine Spielorte vorhanden.')).toBeInTheDocument();
    });

    it('does not delete when the confirm dialog is dismissed', async () => {
        setVenues([makeVenue({ id: 2, name: 'Arena Süd' })]);
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Spielort');
        await user.click(within(row('Arena Süd')).getByRole('button', { name: 'Löschen' }));

        expect(deleteVenueMock).not.toHaveBeenCalled();
        expect(screen.getByRole('row', { name: new RegExp('Arena Süd') })).toBeInTheDocument();
    });

    it('creates a venue and closes the dialog on success', async () => {
        setVenues([]);
        createVenueMock.mockImplementation(async (payload: { name: string }) => {
            const created = makeVenue({ id: 1, name: payload.name });
            setVenues([created]);
            return created;
        });
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('Noch keine Spielorte vorhanden.');
        await user.click(screen.getAllByRole('button', { name: 'Neu' })[0]);
        await user.type(screen.getByLabelText('Name'), 'Arena Nord');
        await user.click(screen.getByRole('button', { name: 'Spielort erstellen' }));

        await waitFor(() => expect(createVenueMock).toHaveBeenCalledWith({ name: 'Arena Nord' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(await screen.findByText('1 Spielort')).toBeInTheDocument();
    });

    it('surfaces a duplicate (422) in the form and keeps the dialog open', async () => {
        setVenues([]);
        createVenueMock.mockRejectedValue(
            new ApiError(422, 'Ein Spielort mit diesem Namen existiert bereits.', {}),
        );
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('Noch keine Spielorte vorhanden.');
        await user.click(screen.getAllByRole('button', { name: 'Neu' })[0]);
        await user.type(screen.getByLabelText('Name'), 'Arena Nord');
        await user.click(screen.getByRole('button', { name: 'Spielort erstellen' }));

        expect(await screen.findByText('Ein Spielort mit diesem Namen existiert bereits.')).toBeInTheDocument();
        // The dialog stays open with the typed name — the admin can correct it
        // instead of retyping everything.
        expect(screen.getByLabelText('Name')).toHaveValue('Arena Nord');
        expect(screen.getByRole('button', { name: 'Spielort erstellen' })).toBeInTheDocument();
    });

    it('keeps the zod name error on the field and does not call the API', async () => {
        setVenues([]);
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('Noch keine Spielorte vorhanden.');
        await user.click(screen.getAllByRole('button', { name: 'Neu' })[0]);
        await user.click(screen.getByRole('button', { name: 'Spielort erstellen' }));

        expect(await screen.findByText('Name ist erforderlich.')).toBeInTheDocument();
        expect(createVenueMock).not.toHaveBeenCalled();
    });

    it('renames a venue through the same dialog', async () => {
        setVenues([makeVenue({ id: 1, name: 'Arena Nord' })]);
        updateVenueMock.mockImplementation(async (id: number, payload: { name?: string }) => {
            const next = makeVenue({ id, name: payload.name ?? '' });
            setVenues([next]);
            return next;
        });
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Spielort');
        await user.click(within(row('Arena Nord')).getByRole('button', { name: 'Umbenennen' }));

        expect(screen.getByRole('heading', { name: 'Spielort umbenennen' })).toBeInTheDocument();
        const nameField = screen.getByLabelText('Name');
        await user.clear(nameField);
        await user.type(nameField, 'Arena Nord neu');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(updateVenueMock).toHaveBeenCalledWith(1, { name: 'Arena Nord neu' }));
        expect(await screen.findByText('Arena Nord neu', { exact: true })).toBeInTheDocument();
    });

    it('reports a failed list load', async () => {
        listVenuesMock.mockRejectedValueOnce(new ApiError(500, 'Serverfehler', {}));
        renderPage();

        expect(await screen.findByText('Spielorte konnten nicht geladen werden.')).toBeInTheDocument();
    });
});
