import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement } from 'react';
import { SWRConfig } from 'swr';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Event, Team, Venue } from '../../api/types';
import { renderWithProviders } from '../../test-setup';
import { EventForm } from './EventForm';
import type { EventFormValues } from './eventFormUtils';
import { TeamForm } from './TeamForm';
import type { TeamFormValues } from './teamFormUtils';

const { venueStore, listVenuesMock, createVenueMock } = vi.hoisted(() => {
    const store: Venue[] = [];
    return {
        venueStore: store,
        listVenuesMock: vi.fn(async () => store.map((venue) => ({ ...venue }))),
        createVenueMock: vi.fn(),
    };
});

const STADIUM_NORD: Venue = {
    id: 11,
    name: 'Stadion Nord',
    is_active: true,
    teams_count: 0,
    events_count: 0,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-01T10:00:00Z',
};

/**
 * `POST /api/admin/venues` commits the row before it answers, so the refetch the
 * combobox awaits right after the create reports it. A stub that ignored the
 * POST would make the create-then-submit tests below prove nothing.
 */
function stubCreateVenue(nextId: number) {
    createVenueMock.mockImplementation(async (payload: { name: string }): Promise<Venue> => {
        const created: Venue = {
            id: nextId,
            name: payload.name,
            is_active: true,
            teams_count: 0,
            events_count: 0,
            created_at: '2026-09-02T10:00:00Z',
            updated_at: '2026-09-02T10:00:00Z',
        };
        venueStore.push(created);
        return created;
    });
}

beforeEach(() => {
    venueStore.splice(0, venueStore.length, { ...STADIUM_NORD });
    createVenueMock.mockReset();
});

afterEach(() => {
    listVenuesMock.mockClear();
});

vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return { ...actual, listVenues: listVenuesMock, createVenue: createVenueMock };
});

vi.mock('../../logic/useAdminTeams', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../logic/useAdminTeams')>();
    return {
        ...actual,
        useAdminTeams: () => ({
            teams: [
                {
                    id: 3,
                    mandant_id: 1,
                    slug: 'heimverein',
                    name: 'Heimverein',
                    venue_id: 11,
                    venue: { id: 11, name: 'Stadion Nord' },
                    created_at: '2026-09-01T10:00:00Z',
                },
            ],
            isLoading: false,
            error: null,
            currentTeamIds: [3],
        }),
    };
});

/**
 * A FRESH SWR cache per render: the combobox refetches the venue list after an
 * inline create, and a cache shared across tests would hand the next test a
 * stale list (and never refetch) instead of the backend's answer.
 */
function withFreshVenueCache(ui: ReactElement) {
    return <SWRConfig value={{ provider: () => new Map() }}>{ui}</SWRConfig>;
}

function renderTeamForm(onSubmit: (values: TeamFormValues) => Promise<void>, initial: Team | null = null) {
    return renderWithProviders(
        withFreshVenueCache(
            <TeamForm
                initial={initial}
                submitLabel="Team speichern"
                submitError={null}
                onSubmit={onSubmit}
                onCancel={vi.fn()}
            />,
        ),
    );
}

function renderEventForm(onSubmit: (values: EventFormValues) => Promise<void>, initial: Event | null) {
    return renderWithProviders(
        withFreshVenueCache(
            <EventForm
                initial={initial}
                submitLabel="Event erstellen"
                submitError={null}
                onSubmit={onSubmit}
                onCancel={vi.fn()}
            />,
        ),
    );
}

describe('TeamForm venue field', () => {
    it('submits the created venue id when the home venue is created inline', async () => {
        // The inline create is the ONLY way to set a home venue for a mandant
        // that has no venue yet, and its selection is committed asynchronously
        // (POST, then the list refetch) — so the id has to survive both awaits.
        stubCreateVenue(42);
        const user = userEvent.setup();
        const onSubmit = vi.fn<(values: TeamFormValues) => Promise<void>>(async () => {});
        renderTeamForm(onSubmit);

        const venueField = screen.getByRole('combobox', { name: 'Heimstätte' });
        await user.type(venueField, 'Arena West');
        await user.click(screen.getByRole('option', { name: 'Arena West neu anlegen' }));
        // The field shows the new venue's name ...
        await waitFor(() => expect(venueField).toHaveValue('Arena West'));
        // ... and the save is live again, i.e. the create has committed its id.
        await waitFor(() => expect(screen.getByRole('button', { name: 'Team speichern' })).toBeEnabled());

        await user.type(screen.getByLabelText('Team-Name'), 'Musterverein');
        await user.type(screen.getByLabelText('Team-Slug'), 'musterverein');
        await user.click(screen.getByRole('button', { name: 'Team speichern' }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        // ... but the form value is its ID, not the name and not ''.
        expect(onSubmit.mock.calls[0][0]).toMatchObject({ venue_id: '42' });
    });

    it('blocks the save while the inline create is in flight, then submits the created id', async () => {
        // The regression this guards: the visible text is the combobox's DRAFT,
        // so it already reads as the venue name while the form still has no id.
        // A real POST is a round trip, and the save used to go through inside
        // that window, persisting the team with `venue_id: null` and silently
        // orphaning the venue that had just been created for it.
        let releaseCreate: (venue: Venue) => void = () => {};
        createVenueMock.mockImplementation(
            () =>
                new Promise<Venue>((resolve) => {
                    releaseCreate = resolve;
                }),
        );
        const user = userEvent.setup();
        const onSubmit = vi.fn<(values: TeamFormValues) => Promise<void>>(async () => {});
        renderTeamForm(onSubmit);

        const venueField = screen.getByRole('combobox', { name: 'Heimstätte' });
        await user.type(venueField, 'Arena West');
        await user.click(screen.getByRole('option', { name: 'Arena West neu anlegen' }));

        await user.type(screen.getByLabelText('Team-Name'), 'Musterverein');
        await user.type(screen.getByLabelText('Team-Slug'), 'musterverein');
        await user.click(screen.getByRole('button', { name: 'Team speichern' }));

        // The create has not answered, so a save would drop the venue reference.
        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Team speichern' })).toBeDisabled();

        releaseCreate({ ...STADIUM_NORD, id: 42, name: 'Arena West' });
        venueStore.push({ ...STADIUM_NORD, id: 42, name: 'Arena West' });

        // The save unlocks once the create committed its id.
        const submit = await waitFor(() => {
            const button = screen.getByRole('button', { name: 'Team speichern' });
            expect(button).toBeEnabled();
            return button;
        });
        await user.click(submit);

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(onSubmit.mock.calls[0][0]).toMatchObject({ venue_id: '42' });
    });

    it('replaces the free-text Heimstätte with a venue combobox and submits the id', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn<(values: TeamFormValues) => Promise<void>>(async () => {});
        renderTeamForm(onSubmit);

        const venueField = screen.getByRole('combobox', { name: 'Heimstätte' });
        await user.click(venueField);
        await user.click(screen.getByRole('option', { name: /Stadion Nord/ }));

        await user.type(screen.getByLabelText('Team-Name'), 'Musterverein');
        await user.type(screen.getByLabelText('Team-Slug'), 'musterverein');
        await user.click(screen.getByRole('button', { name: 'Team speichern' }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        // The payload field is a REFERENCE, not the name string.
        expect(onSubmit.mock.calls[0][0]).toMatchObject({ venue_id: '11' });
    });

    it('submits an empty home venue as an empty reference when nothing is picked', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn<(values: TeamFormValues) => Promise<void>>(async () => {});
        renderTeamForm(onSubmit);

        await user.type(screen.getByLabelText('Team-Name'), 'Musterverein');
        await user.type(screen.getByLabelText('Team-Slug'), 'musterverein');
        await user.click(screen.getByRole('button', { name: 'Team speichern' }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(onSubmit.mock.calls[0][0]).toMatchObject({ venue_id: '' });
    });

    it('shows the stored home venue of an edited team', async () => {
        renderTeamForm(vi.fn(async () => {}), {
            id: 3,
            mandant_id: 1,
            slug: 'heimverein',
            name: 'Heimverein',
            venue_id: 11,
            venue: { id: 11, name: 'Stadion Nord' },
            created_at: '2026-09-01T10:00:00Z',
        });

        // `valueLabel` covers the window before the venue list has loaded, so an
        // editing form is never blank.
        await waitFor(() => expect(screen.getByRole('combobox', { name: 'Heimstätte' })).toHaveValue('Stadion Nord'));
    });
});

describe('EventForm venue field', () => {
    it('submits the created venue id when the Spielort is created inline', async () => {
        // Same asynchronous commit as the team form: the event form defaults the
        // venue from the own team, so a lost id would silently save the event at
        // the team's home venue instead of the one just created.
        stubCreateVenue(42);
        const user = userEvent.setup();
        const onSubmit = vi.fn<(values: EventFormValues) => Promise<void>>(async () => {});
        renderEventForm(onSubmit, null);

        // The team default fills the field asynchronously; the E2E REPLACES its
        // text (Playwright `fill`), so wait for the default and then clear.
        const venueField = screen.getByRole('combobox', { name: 'Spielort' });
        await waitFor(() => expect(venueField).toHaveValue('Stadion Nord'));
        await user.clear(venueField);
        await user.type(venueField, 'Arena West');
        await user.click(screen.getByRole('option', { name: 'Arena West neu anlegen' }));
        await waitFor(() => expect(venueField).toHaveValue('Arena West'));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Event erstellen' })).toBeEnabled());

        await user.type(screen.getByLabelText('Titel'), 'Heimspiel');
        await user.click(screen.getByRole('button', { name: 'Event erstellen' }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(onSubmit.mock.calls[0][0]).toMatchObject({ venue_id: '42' });
    });

    it('blocks the save while the inline create is in flight, then submits the created id', async () => {
        // Same defect as the team form: the combobox is shared, so a save inside
        // the create's round trip would persist the event with the team default
        // venue instead of the one the admin just created.
        let releaseCreate: (venue: Venue) => void = () => {};
        createVenueMock.mockImplementation(
            () =>
                new Promise<Venue>((resolve) => {
                    releaseCreate = resolve;
                }),
        );
        const user = userEvent.setup();
        const onSubmit = vi.fn<(values: EventFormValues) => Promise<void>>(async () => {});
        renderEventForm(onSubmit, null);

        // The team default fills the field asynchronously; the E2E REPLACES its
        // text (Playwright `fill`), so wait for the default and then clear.
        const venueField = screen.getByRole('combobox', { name: 'Spielort' });
        await waitFor(() => expect(venueField).toHaveValue('Stadion Nord'));
        await user.clear(venueField);
        await user.type(venueField, 'Arena West');
        await user.click(screen.getByRole('option', { name: 'Arena West neu anlegen' }));

        await user.type(screen.getByLabelText('Titel'), 'Heimspiel');
        await user.click(screen.getByRole('button', { name: 'Event erstellen' }));

        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Event erstellen' })).toBeDisabled();

        releaseCreate({ ...STADIUM_NORD, id: 42, name: 'Arena West' });
        venueStore.push({ ...STADIUM_NORD, id: 42, name: 'Arena West' });

        const submit = await waitFor(() => {
            const button = screen.getByRole('button', { name: 'Event erstellen' });
            expect(button).toBeEnabled();
            return button;
        });
        await user.click(submit);

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(onSubmit.mock.calls[0][0]).toMatchObject({ venue_id: '42' });
    });

    it('replaces the free-text Spielort with a venue combobox and submits the id', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn<(values: EventFormValues) => Promise<void>>(async () => {});
        renderEventForm(onSubmit, null);

        const venueField = screen.getByRole('combobox', { name: 'Spielort' });
        await user.click(venueField);
        await user.click(screen.getByRole('option', { name: /Stadion Nord/ }));

        await user.type(screen.getByLabelText('Titel'), 'Heimspiel');
        await user.click(screen.getByRole('button', { name: 'Event erstellen' }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(onSubmit.mock.calls[0][0]).toMatchObject({ venue_id: '11' });
    });

    it('defaults the venue from the team home venue when a team is chosen', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn<(values: EventFormValues) => Promise<void>>(async () => {});
        renderEventForm(onSubmit, null);

        // The team_admin scope defaults the team to their own; the venue follows
        // the team's `venue_id` (W12), not a copied name string.
        await waitFor(() => expect(screen.getByLabelText('Team')).toHaveValue('3'));
        await waitFor(() => expect(screen.getByRole('combobox', { name: 'Spielort' })).toHaveValue('Stadion Nord'));

        await user.type(screen.getByLabelText('Titel'), 'Heimspiel');
        await user.click(screen.getByRole('button', { name: 'Event erstellen' }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(onSubmit.mock.calls[0][0]).toMatchObject({ venue_id: '11' });
    });

    it('shows the stored venue of an edited event', async () => {
        const event: Event = {
            id: 5,
            mandant_id: 1,
            team_id: 3,
            title: 'Auswärtsspiel',
            date: null,
            venue_id: 11,
            venue: { id: 11, name: 'Stadion Nord' },
            competition: null,
            deadline_start: null,
            deadline_end: null,
            active: true,
            team: { id: 3, name: 'Heimverein' },
        };
        renderEventForm(vi.fn(async () => {}), event);

        await waitFor(() => expect(screen.getByRole('combobox', { name: 'Spielort' })).toHaveValue('Stadion Nord'));
    });
});
