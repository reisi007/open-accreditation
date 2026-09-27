import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { Event, Team } from '../../api/types';
import { renderWithProviders } from '../../test-setup';
import { EventForm } from './EventForm';
import type { EventFormValues } from './eventFormUtils';
import { TeamForm } from './TeamForm';
import type { TeamFormValues } from './teamFormUtils';

// The combobox owns the venue list (SWR → `listVenues`); the team select and its
// venue default come from `useAdminTeams`. Both are stubbed to fixed data so the
// two tests below assert the WIRING — that the form field is a venue
// reference — and not the list fetching the combobox test already covers.
vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return {
        ...actual,
        listVenues: vi.fn(async () => [
            {
                id: 11,
                name: 'Stadion Nord',
                is_active: true,
                teams_count: 0,
                events_count: 0,
                created_at: '2026-09-01T10:00:00Z',
                updated_at: '2026-09-01T10:00:00Z',
            },
        ]),
    };
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

function renderTeamForm(onSubmit: (values: TeamFormValues) => Promise<void>, initial: Team | null = null) {
    return renderWithProviders(
        <TeamForm
            initial={initial}
            submitLabel="Team speichern"
            submitError={null}
            onSubmit={onSubmit}
            onCancel={vi.fn()}
        />,
    );
}

function renderEventForm(onSubmit: (values: EventFormValues) => Promise<void>, initial: Event | null) {
    return renderWithProviders(
        <EventForm
            initial={initial}
            submitLabel="Event erstellen"
            submitError={null}
            onSubmit={onSubmit}
            onCancel={vi.fn()}
        />,
    );
}

describe('TeamForm venue field', () => {
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
