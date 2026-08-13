import { request } from '@playwright/test';

const FRONTEND_BASE_URL = 'http://localhost:5173';

/**
 * Logs the bootstrap admin in via the API and returns a request context that
 * carries the session cookie for subsequent admin API calls.
 *
 * @returns {Promise<import('@playwright/test').APIRequestContext>}
 */
export async function loginAdminApi() {
    const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
    const login = await api.post('/api/auth/login', { data: { email: 'admin@example.com', password: 'admin' } });
    if (login.status() !== 200) {
        throw new Error(`Admin API login failed with status ${login.status()}`);
    }
    return api;
}

/**
 * Ensures the primary mandant (the domain-derived current mandant in local
 * dev) has teams enabled and at least one team with a home venue, so the
 * category/event UI can create team-level rows. Returns the first team.
 *
 * @returns {Promise<{ id: number; name: string; home_venue: string | null }>}
 */
export async function ensurePrimaryMandantHasTeam() {
    const api = await loginAdminApi();
    try {
        const mandantsBody = await (await api.get('/api/admin/mandants')).json();
        const mandants = mandantsBody.data ?? [];
        let primary = null;
        for (const mandant of mandants) {
            if (mandant.is_primary) {
                primary = mandant;
                break;
            }
        }
        if (primary === null) {
            primary = mandants[0] ?? null;
        }
        if (!primary) {
            throw new Error('No mandant found for team setup');
        }

        if (!primary.teams_enabled) {
            const enable = await api.put(`/api/admin/mandants/${primary.id}`, { data: { teams_enabled: true } });
            if (enable.status() !== 200) {
                throw new Error(`Enabling teams failed with status ${enable.status()}`);
            }
        }

        const teamsBody = await (await api.get(`/api/admin/mandants/${primary.id}/teams`)).json();
        const teamsList = teamsBody.data ?? [];
        if (teamsList.length > 0) {
            return teamsList[0];
        }

        const suffix = Date.now();
        const create = await api.post(`/api/admin/mandants/${primary.id}/teams`, {
            data: {
                name: `E2E Heimverein ${suffix}`,
                slug: `e2e-heimverein-${suffix}`,
                home_venue: 'E2E Heimstadion',
            },
        });
        if (create.status() !== 201) {
            throw new Error(`Creating the setup team failed with status ${create.status()}`);
        }
        const createdBody = await create.json();

        return createdBody.data;
    } finally {
        await api.dispose();
    }
}
