import { request } from '@playwright/test';
import { MailpitHelper } from './mailpit';

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

/**
 * ISO date string (Y-m-d) `days` days from today in local time.
 *
 * @param {number} days
 */
function isoDateInDays(days = 0) {
    const date = new Date();
    date.setDate(date.getDate() + days);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

/**
 * Creates a unique active, event-scoped accreditation (plus the backing
 * category and event) so the public accreditation list and the application
 * flow have deterministic content. The deadline window is open (past start,
 * future end), quota 5.
 *
 * @returns {Promise<{ accreditation: object; categoryName: string; eventTitle: string }>}
 */
export async function ensurePrimaryMandantAccreditation() {
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
            throw new Error('No mandant found for accreditation setup');
        }

        const suffix = Date.now();
        const categoryName = `E2E Akkreditierung ${suffix}`;
        const categorySlug = `e2e-akkreditierung-${suffix}`;
        const category = await api.post('/api/admin/categories', {
            data: { name: categoryName, slug: categorySlug },
        });
        if (category.status() !== 201) {
            throw new Error(`Creating the setup category failed with status ${category.status()}`);
        }
        const categoryData = (await category.json()).data;

        const eventTitle = `E2E Akkreditierung Event ${suffix}`;
        const event = await api.post('/api/admin/events', {
            data: { title: eventTitle, active: true },
        });
        if (event.status() !== 201) {
            throw new Error(`Creating the setup event failed with status ${event.status()}`);
        }
        const eventData = (await event.json()).data;

        const accreditation = await api.post('/api/admin/accreditations', {
            data: {
                category_id: categoryData.id,
                scope: 'event',
                event_id: eventData.id,
                quota: 5,
                deadline_start: isoDateInDays(-5),
                deadline_end: isoDateInDays(30),
                auto_approve: false,
                active: true,
            },
        });
        if (accreditation.status() !== 201) {
            throw new Error(`Creating the setup accreditation failed with status ${accreditation.status()}`);
        }
        const accreditationData = (await accreditation.json()).data;

        return { accreditation: accreditationData, categoryName, eventTitle };
    } finally {
        await api.dispose();
    }
}

/**
 * Creates a unique active event-scoped accreditation (category + event) plus
 * an active park sub-accreditation (Parkkarte, quota 1, open deadline window)
 * so the "Meine Akkreditierungen" sub-accreditation flow has deterministic
 * content. Returns the main accreditation and the sub-accreditation.
 *
 * @returns {Promise<{ accreditation: object; subAccreditation: object; categoryName: string; eventTitle: string }>}
 */
export async function ensurePrimaryMandantSubAccreditation() {
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
            throw new Error('No mandant found for sub-accreditation setup');
        }

        const suffix = Date.now();
        const categoryName = `E2E Sub Akkreditierung ${suffix}`;
        const category = await api.post('/api/admin/categories', {
            data: { name: categoryName, slug: `e2e-sub-akkreditierung-${suffix}` },
        });
        if (category.status() !== 201) {
            throw new Error(`Creating the setup category failed with status ${category.status()}`);
        }
        const categoryData = (await category.json()).data;

        const eventTitle = `E2E Sub Akkreditierung Event ${suffix}`;
        const event = await api.post('/api/admin/events', {
            data: { title: eventTitle, active: true },
        });
        if (event.status() !== 201) {
            throw new Error(`Creating the setup event failed with status ${event.status()}`);
        }
        const eventData = (await event.json()).data;

        const accreditation = await api.post('/api/admin/accreditations', {
            data: {
                category_id: categoryData.id,
                scope: 'event',
                event_id: eventData.id,
                quota: 5,
                deadline_start: isoDateInDays(-5),
                deadline_end: isoDateInDays(30),
                auto_approve: false,
                active: true,
            },
        });
        if (accreditation.status() !== 201) {
            throw new Error(`Creating the setup accreditation failed with status ${accreditation.status()}`);
        }
        const accreditationData = (await accreditation.json()).data;

        const subAccreditation = await api.post(
            `/api/admin/accreditations/${accreditationData.id}/sub-accreditations`,
            {
                data: {
                    type: 'park',
                    quota: 1,
                    deadline_start: isoDateInDays(-5),
                    deadline_end: isoDateInDays(30),
                    auto_approve: false,
                    active: true,
                },
            },
        );
        if (subAccreditation.status() !== 201) {
            throw new Error(`Creating the setup sub-accreditation failed with status ${subAccreditation.status()}`);
        }
        const subAccreditationData = (await subAccreditation.json()).data;

        return {
            accreditation: accreditationData,
            subAccreditation: subAccreditationData,
            categoryName,
            eventTitle,
        };
    } finally {
        await api.dispose();
    }
}

/**
 * Registers a throwaway user via the API and activates it through the Mailpit
 * activation link. Returns the credentials for the subsequent UI logins.
 *
 * @returns {Promise<{ email: string; password: string }>}
 */
export async function registerAndActivateUser() {
    const email = `sub-${Date.now()}@example.test`;
    const password = 'SecurePassw0rd!';
    const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
    try {
        const register = await api.post('/api/auth/register', {
            data: { name: 'E2E Sub User', email, password, password_confirmation: password },
        });
        if (register.status() !== 201) {
            throw new Error(`User registration failed with status ${register.status()}`);
        }

        const mailpit = new MailpitHelper();
        const activationPath = await mailpit.extractActivationPath(email);
        const activationUrl = new URL(activationPath, FRONTEND_BASE_URL).toString();
        const activation = await api.get(activationUrl);
        if (activation.status() !== 200) {
            let bodyText = '';
            try {
                bodyText = await activation.text();
            } catch {
                bodyText = '<unreadable>';
            }
            throw new Error(
                `User activation failed with status ${activation.status()} url=${activationUrl} body=${bodyText.slice(0, 200)}`,
            );
        }

        return { email, password };
    } finally {
        await api.dispose();
    }
}

/**
 * Creates a unique active portal event (team-scoped, future date + deadline)
 * so the public portal calendar has deterministic content. Returns the event,
 * its team and the mandant name shown as the portal heading.
 *
 * @returns {Promise<{ event: object; team: object; mandantName: string }>}
 */
export async function ensurePrimaryMandantActivePortalEvent() {
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
            throw new Error('No mandant found for portal event setup');
        }

        if (!primary.teams_enabled) {
            const enable = await api.put(`/api/admin/mandants/${primary.id}`, { data: { teams_enabled: true } });
            if (enable.status() !== 200) {
                throw new Error(`Enabling teams failed with status ${enable.status()}`);
            }
        }

        const teamsBody = await (await api.get(`/api/admin/mandants/${primary.id}/teams`)).json();
        const teamsList = teamsBody.data ?? [];
        let team = teamsList[0];
        if (!team) {
            const suffix = Date.now();
            const teamCreate = await api.post(`/api/admin/mandants/${primary.id}/teams`, {
                data: {
                    name: `E2E Heimverein ${suffix}`,
                    slug: `e2e-heimverein-${suffix}`,
                    home_venue: 'E2E Heimstadion',
                },
            });
            if (teamCreate.status() !== 201) {
                throw new Error(`Creating the setup team failed with status ${teamCreate.status()}`);
            }
            team = (await teamCreate.json()).data;
        }

        const title = `Portal-Test ${Date.now()}`;
        const create = await api.post('/api/admin/events', {
            data: {
                title,
                team_id: team.id,
                date: isoDateInDays(60),
                venue: 'E2E Portal Arena',
                competition: 'E2E Wettbewerb',
                deadline_start: isoDateInDays(20),
                deadline_end: isoDateInDays(30),
                active: true,
            },
        });
        if (create.status() !== 201) {
            throw new Error(`Creating the portal event failed with status ${create.status()}`);
        }
        const createdBody = await create.json();

        return { event: createdBody.data, team, mandantName: primary.name };
    } finally {
        await api.dispose();
    }
}
