import { screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { SWRConfig } from 'swr';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../../test-setup';
import { MandantDetailPage } from './MandantDetailPage';

const { getMandantMock, listDomainsMock, listTeamsMock, fetchMock } = vi.hoisted(() => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ message: 'not found' }), { status: 404 }));
    return {
        fetchMock,
        getMandantMock: vi.fn(),
        listDomainsMock: vi.fn(async () => []),
        listTeamsMock: vi.fn(async () => []),
    };
});

vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return {
        ...actual,
        getMandant: getMandantMock,
        listDomains: listDomainsMock,
        listTeams: listTeamsMock,
    };
});

function renderAt(id: string) {
    return renderWithProviders(
        <SWRConfig value={{ provider: () => new Map() }}>
            <MemoryRouter initialEntries={[`/admin/mandants/${id}`]}>
                <Routes>
                    <Route path="/admin/mandants/:id" element={<MandantDetailPage />} />
                </Routes>
            </MemoryRouter>
        </SWRConfig>,
    );
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
