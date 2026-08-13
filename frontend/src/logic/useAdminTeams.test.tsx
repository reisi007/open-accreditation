import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SWRConfig } from 'swr';
import { useAdminTeams } from './useAdminTeams';

const teamsPayload = [
    { id: 10, mandant_id: 5, slug: 'heim', name: 'Heimverein', home_venue: 'Heimstadion', created_at: '2026-01-01T00:00:00Z' },
    { id: 11, mandant_id: 5, slug: 'gast', name: 'Gastverein', home_venue: null, created_at: '2026-01-01T00:00:00Z' },
];

function AdminTeamsProbe() {
    const { teams, isLoading, error, currentTeamIds } = useAdminTeams();

    return (
        <div>
            <span data-testid="loading">{String(isLoading)}</span>
            <span data-testid="error">{String(Boolean(error))}</span>
            <span data-testid="teamIds">{currentTeamIds.join(',')}</span>
            <span data-testid="teams">{(teams ?? []).map((team) => `${team.id}:${team.name}`).join('|')}</span>
        </div>
    );
}

function renderProbe() {
    return render(
        <SWRConfig value={{ provider: () => new Map() }}>
            <AdminTeamsProbe />
        </SWRConfig>,
    );
}

function stubFetch(mePayload: unknown) {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, _init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : String(input);
        if (url === '/api/auth/me') {
            return new Response(JSON.stringify({ data: mePayload }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        if (url === '/api/admin/mandants/5/teams') {
            return new Response(JSON.stringify({ data: teamsPayload }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        return new Response(JSON.stringify({ message: 'not found' }), {
            status: 404,
            headers: { 'Content-Type': 'application/json' },
        });
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useAdminTeams', () => {
    it('team_admin fetches only his own teams and exposes their ids', async () => {
        const fetchMock = stubFetch({
            id: 2,
            name: 'Team Admin',
            email: 'team@example.com',
            roles: [
                { slug: 'team_admin', name: 'Team-Admin', mandant_id: 5, team_id: 10 },
                { slug: 'team_admin', name: 'Team-Admin', mandant_id: 5, team_id: 11 },
            ],
        });
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('teams')).toHaveTextContent('10:Heimverein|11:Gastverein'));
        expect(screen.getByTestId('teamIds')).toHaveTextContent('10,11');
        expect(screen.getByTestId('error')).toHaveTextContent('false');
        // The mandant list is super_admin-only — must not be fetched.
        expect(fetchMock.mock.calls.some(([url]) => url === '/api/admin/mandants')).toBe(false);
    });

    it('mandant_admin loads all teams and has no team scope', async () => {
        const fetchMock = stubFetch({
            id: 3,
            name: 'Mandant Admin',
            email: 'mandant@example.com',
            roles: [{ slug: 'mandant_admin', name: 'Mandant-Admin', mandant_id: 5, team_id: null }],
        });
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('teams')).toHaveTextContent('10:Heimverein|11:Gastverein'));
        expect(screen.getByTestId('teamIds')).toHaveTextContent('');
        expect(screen.getByTestId('error')).toHaveTextContent('false');
        expect(fetchMock.mock.calls.some(([url]) => url === '/api/admin/mandants')).toBe(false);
    });
});
