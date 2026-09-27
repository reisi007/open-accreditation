import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { SWRConfig, mutate as globalMutate } from 'swr';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../../test-setup';
import { MandantDetailPage } from './MandantDetailPage';

const {
    getMandantMock,
    listDomainsMock,
    listTeamsMock,
    listVenuesMock,
    createTeamMock,
    createVenueMock,
    fetchMock,
} = vi.hoisted(() => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ message: 'not found' }), { status: 404 }));
    return {
        fetchMock,
        getMandantMock: vi.fn(),
        listDomainsMock: vi.fn(async () => []),
        listTeamsMock: vi.fn(async () => []),
        listVenuesMock: vi.fn(async () => []),
        createTeamMock: vi.fn(async () => ({})),
        createVenueMock: vi.fn(async () => ({
            id: 99,
            name: 'Neue Halle',
            is_active: true,
            teams_count: 0,
            events_count: 0,
            created_at: '2026-01-01T00:00:00Z',
            updated_at: '2026-01-01T00:00:00Z',
        })),
    };
});

vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return {
        ...actual,
        getMandant: getMandantMock,
        listDomains: listDomainsMock,
        listTeams: listTeamsMock,
        listVenues: listVenuesMock,
        createTeam: createTeamMock,
        createVenue: createVenueMock,
    };
});

const mandant = {
    id: 7,
    slug: 'verband',
    name: 'Verband',
    logo_url: null,
    header_url: null,
    impressum_text: null,
    privacy_text: null,
    teams_enabled: true,
    is_primary: false,
    is_active: true,
    smtp_config: null,
    domains: [],
};

/**
 * `isolated` gives each render its own SWR cache — the default for the load and
 * guard tests.
 *
 * `shared` uses the app's REAL cache, and is the only mode in which the
 * invalidation is observable: `refreshVenueLists()` mutates through the module
 * level `globalMutate`, which reaches the default cache only. Against an
 * isolated `new Map()` the invalidate is a silent no-op and the assertion would
 * pass for the wrong reason. `dedupingInterval: 0` because a test fires the
 * invalidation milliseconds after the initial load, which the 2s default would
 * dedupe away.
 */
function renderAt(id: string, cache: 'isolated' | 'shared' = 'isolated') {
    const swr = cache === 'isolated' ? (
        <SWRConfig value={{ provider: () => new Map() }}>
            <MandantDetailPage />
        </SWRConfig>
    ) : (
        <SWRConfig value={{ dedupingInterval: 0 }}>
            <MandantDetailPage />
        </SWRConfig>
    );

    return renderWithProviders(
        <MemoryRouter initialEntries={[`/admin/mandants/${id}`]}>
            <Routes>
                <Route path="/admin/mandants/:id" element={swr} />
            </Routes>
        </MemoryRouter>,
    );
}

/** The page loaded, with the team form open (the venue combobox lives in it). */
async function renderWithTeamForm(cache: 'isolated' | 'shared' = 'isolated') {
    getMandantMock.mockResolvedValue(mandant);
    const view = renderAt('7', cache);
    const user = userEvent.setup();

    await screen.findByRole('heading', { level: 1, name: 'Verband' });
    await user.click(screen.getByRole('button', { name: 'Team hinzufügen' }));

    return { user, ...view };
}

afterEach(() => {
    vi.clearAllMocks();
    vi.unstubAllGlobals();
    vi.stubGlobal('fetch', fetchMock);
});

describe('MandantDetailPage', () => {
    it('does not fire any request for a non-numeric route param', async () => {
        vi.stubGlobal('fetch', fetchMock);
        renderAt('abc');

        expect(await screen.findByText('Mandant konnte nicht geladen werden.')).toBeInTheDocument();
        // Without the `Number.isInteger` guard this built three
        // `/api/admin/mandants/NaN` SWR keys and fired three 404s.
        expect(fetchMock).not.toHaveBeenCalled();
        expect(getMandantMock).not.toHaveBeenCalled();
        expect(listDomainsMock).not.toHaveBeenCalled();
        expect(listTeamsMock).not.toHaveBeenCalled();
    });

    it('does not fire any request for a zero or negative id', async () => {
        vi.stubGlobal('fetch', fetchMock);
        renderAt('0');

        expect(await screen.findByText('Mandant konnte nicht geladen werden.')).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('loads the mandant for a valid numeric id', async () => {
        vi.stubGlobal('fetch', fetchMock);
        getMandantMock.mockResolvedValue({
            id: 7,
            slug: 'verband',
            name: 'Verband',
            logo_url: null,
            header_url: null,
            impressum_text: null,
            privacy_text: null,
            teams_enabled: false,
            is_primary: false,
            is_active: true,
            smtp_config: null,
            domains: [],
        });
        renderAt('7');

        expect(await screen.findByRole('heading', { level: 1, name: 'Verband' })).toBeInTheDocument();
        await waitFor(() => expect(getMandantMock).toHaveBeenCalledWith(7));
    });
});

describe('MandantDetailPage — the venue surface of the addressed mandant', () => {
    // The shared-cache render below writes to SWR's global cache; empty it
    // between tests so the next render cannot read a leftover entry.
    afterEach(async () => {
        await globalMutate(() => true, undefined, { revalidate: false });
    });

    it('reads the venues of the mandant the URL addresses, not of the host', async () => {
        await renderWithTeamForm();

        await waitFor(() => expect(listVenuesMock).toHaveBeenCalledWith(7));
    });

    it('creates an inline venue IN the addressed mandant', async () => {
        const { user } = await renderWithTeamForm();

        const combobox = screen.getByRole('combobox', { name: 'Heimstätte' });
        await user.click(combobox);
        await user.type(combobox, 'Neue Halle');

        const offer = await screen.findByRole('option', { name: 'Neue Halle neu anlegen' });
        await user.click(offer);

        // The bug this pins: the create used to go to the host-scoped endpoint
        // and the row landed in the host mandant, without any error.
        await waitFor(() => expect(createVenueMock).toHaveBeenCalledWith({ name: 'Neue Halle' }, 7));
    });

    it('refreshes the venue list after a team save, so its teams_count cannot go stale', async () => {
        const { user } = await renderWithTeamForm('shared');
        await waitFor(() => expect(listVenuesMock).toHaveBeenCalledTimes(1));

        await user.type(screen.getByLabelText('Team-Name'), 'FC A');
        await user.type(screen.getByLabelText('Team-Slug'), 'fc-a');
        await user.click(screen.getByRole('button', { name: 'Team speichern' }));

        await waitFor(() => expect(createTeamMock).toHaveBeenCalledWith(7, { name: 'FC A', slug: 'fc-a', venue_id: null }));
        // The invalidation is derived from the mandant-scoped key now, so the
        // mounted list has to be refetched through it. Bound to the old
        // host-scoped constant it would silently stop working here.
        await waitFor(() => expect(listVenuesMock).toHaveBeenCalledTimes(2));
    });
});
