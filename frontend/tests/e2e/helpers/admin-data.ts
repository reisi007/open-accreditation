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
 * Registers a throwaway user via the API, activates it through the Mailpit
 * activation link, logs in via the API and applies for the given accreditation.
 * The user ends the flow logged out again (each helper call is a fresh,
 * disposable account — the unique (accreditation_id, user_id) apply constraint
 * requires a new user per application).
 *
 * @returns {Promise<{ email: string; password: string }>}
 */
export async function registerAndApplyForAccreditation(accreditationId = 0, name = 'E2E Antragsteller') {
    const email = `approve-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.test`;
    const password = 'SecurePassw0rd!';
    const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
    try {
        const register = await api.post('/api/auth/register', {
            data: { name, email, password, password_confirmation: password },
        });
        if (register.status() !== 201) {
            throw new Error(`User registration failed with status ${register.status()}`);
        }

        const mailpit = new MailpitHelper();
        const activationPath = await mailpit.extractActivationPath(email);
        const activation = await api.get(new URL(activationPath, FRONTEND_BASE_URL).toString());
        if (activation.status() !== 200) {
            throw new Error(`User activation failed with status ${activation.status()}`);
        }

        const login = await api.post('/api/auth/login', { data: { email, password } });
        if (login.status() !== 200) {
            throw new Error(`User login failed with status ${login.status()}`);
        }

        const apply = await api.post(`/api/accreditations/${accreditationId}/apply`);
        if (apply.status() !== 200 && apply.status() !== 201) {
            throw new Error(`Apply failed with status ${apply.status()}`);
        }

        const logout = await api.post('/api/auth/logout');
        if (logout.status() !== 200) {
            throw new Error(`Logout failed with status ${logout.status()}`);
        }

        return { email, password };
    } finally {
        await api.dispose();
    }
}

/**
 * Registers a throwaway user via the API, activates it through the Mailpit
 * activation link, logs in via the API, uploads a portrait (so the public
 * verify page can stream a real photo), applies for the given accreditation
 * and logs out again. Each call is a fresh, disposable account.
 *
 * @returns {Promise<{ email: string; password: string }>}
 */
export async function registerUploadPortraitAndApply(accreditationId = 0, name = 'E2E Badge Inhaber') {
    const email = `badge-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.test`;
    const password = 'SecurePassw0rd!';
    const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
    try {
        const register = await api.post('/api/auth/register', {
            data: { name, email, password, password_confirmation: password },
        });
        if (register.status() !== 201) {
            throw new Error(`User registration failed with status ${register.status()}`);
        }

        const mailpit = new MailpitHelper();
        const activationPath = await mailpit.extractActivationPath(email);
        const activation = await api.get(new URL(activationPath, FRONTEND_BASE_URL).toString());
        if (activation.status() !== 200) {
            throw new Error(`User activation failed with status ${activation.status()}`);
        }

        const login = await api.post('/api/auth/login', { data: { email, password } });
        if (login.status() !== 200) {
            throw new Error(`User login failed with status ${login.status()}`);
        }

        // Realistic head-and-shoulders portrait fixture (96×120 PNG, ~0.7 KB) —
        // programmatically drawn with Python/PIL (light-gray background,
        // skin-tone head, dark hair cap, simple clothing band) so the public
        // verify page shows a human-looking image when scaled to ~128×160.
        // Small enough for a throwaway portrait and passes the backend's
        // image/dimension validation (max 2000px, no minimum).
        const portrait = await api.post('/api/user/media', {
            multipart: {
                type: 'portrait',
                file: {
                    name: 'portrait.png',
                    mimeType: 'image/png',
                    buffer: Buffer.from(
                        'iVBORw0KGgoAAAANSUhEUgAAAGAAAAB4CAIAAACCf2CZAAACjklEQVR42u2cu0oDQRSGZ0fTSFBRH0REsVV8BYNWFmJlqRaS2sJCrcTKykrJM0hqMQRfQiy8oCI2YrBYCEuuuztn5xK/v1rIJHPm23/O3MJEj0/PCvWXBgGAAAQgAAEIQAACEAIQgAAEIAABaLQ0bvj9ytqy/42s3d7hILoYgAAEIAABCAEIQAACEIAABCAAIQABCEAAAhCAAAQgACEAuQNkcmipvD9WxUFWAPlsIvPYcJAq9t8dsS6PD+KHncMTH1rVjseLLvZxXysiMhE6ydhcOiirPvVE/DDZ+i6ivEdJuvsVDTVRu7UdzyLlu2s3NJF25Z2Ubc5anpn0SCw1rk/3cn/3vLp9Xt22X69tB/WLtTvLDs676csXQUdgFJtaqnRkwXKplYx4c/+su4WZRqWh5ZNoyqXW14/uiNDrYb4npqyjtWXXCANKmqhtnzSYCkpzSRMZ2kfMQVNLld+Hm3xuKiITx+9pbH7Dl7WYSQuH8rLQjwYokrqaIo2D0ujl9V0pNTc7bf5TIg4SG+ZFohGUVDzMpAEU4nbHAIlkH08d5E8aEoyELmYXkA8mko3BpYOa9Uaz3pAq5vtEMd+ksd3yhdXFrJ/asbDjUWxhdTGmMMAj6ekE46AcK4+egLKiKSIDRsVdEyi1OnM7PujgIrZclw40bmu16KCjt/D7Oug3bMGhOtwcYaf/6kDzqLURILJ/G7Dh8G95uRe5ui45ByYnK+HI7X3S8YFav9M0pVR8wmV+vBX2jmLHYbFiT1qxaQ8gACEAAUhiHrSyfuSw+outmTTFdq/ecBBdDEAAAhACEIAApP7TfpDDGSAOAhCAAAQgAAEIQAhAAAIQgAAEIAABCAEIQAACEIAANFL6A/UV1Rn7fVgJAAAAAElFTkSuQmCC',
                        'base64',
                    ),
                },
            },
        });
        if (portrait.status() !== 201) {
            throw new Error(`Portrait upload failed with status ${portrait.status()}`);
        }

        const apply = await api.post(`/api/accreditations/${accreditationId}/apply`);
        if (apply.status() !== 200 && apply.status() !== 201) {
            throw new Error(`Apply failed with status ${apply.status()}`);
        }

        const logout = await api.post('/api/auth/logout');
        if (logout.status() !== 200) {
            throw new Error(`Logout failed with status ${logout.status()}`);
        }

        return { email, password };
    } finally {
        await api.dispose();
    }
}

/**
 * P4 badge E2E setup: ensures a fresh event-scoped accreditation, creates one
 * applicant with a portrait + approved application (apply + allocate via API),
 * and returns the approved application including its `qr_url` (relative
 * `/verify/<token>`).
 */
export async function ensurePrimaryMandantApprovedApplication() {
    const { accreditation, categoryName, eventTitle } = await ensurePrimaryMandantAccreditation();
    await registerUploadPortraitAndApply(accreditation.id, 'E2E Badge Inhaber');
    await allocateAccreditationApi(accreditation.id, 'all');

    const api = await loginAdminApi();
    try {
        const body = await (await api.get(`/api/admin/applications?accreditation_id=${accreditation.id}`)).json();
        const applications = body.data ?? [];
        let approved = null;
        for (const entry of applications) {
            if (entry.status === 'approved') {
                approved = entry;
                break;
            }
        }
        if (!approved || typeof approved.qr_url !== 'string') {
            throw new Error('No approved application with qr_url after allocation');
        }
        return { accreditation, categoryName, eventTitle, application: approved };
    } finally {
        await api.dispose();
    }
}

/**
 * P6 wallet E2E setup: creates a fresh event-scoped accreditation with an
 * active park sub-accreditation (quota 1), one throwaway user with an
 * approved main application AND an approved park sub-application (apply +
 * allocate via API, in the contract order the sub-apply requires an approved
 * main first). Returns the created resources and the user credentials.
 */
export async function ensurePrimaryMandantWalletSetup() {
    const { accreditation, subAccreditation, categoryName } = await ensurePrimaryMandantSubAccreditation();
    const user = await registerAndActivateUser();
    const password = user.password;

    // Main application (requested) as the user.
    const userApi = await request.newContext({ baseURL: FRONTEND_BASE_URL });
    try {
        const login = await userApi.post('/api/auth/login', { data: { email: user.email, password: user.password } });
        if (login.status() !== 200) {
            throw new Error(`Wallet setup user login failed with status ${login.status()}`);
        }
        const apply = await userApi.post(`/api/accreditations/${accreditation.id}/apply`);
        if (apply.status() !== 200 && apply.status() !== 201) {
            throw new Error(`Wallet setup main apply failed with status ${apply.status()}`);
        }
        await userApi.post('/api/auth/logout');
    } finally {
        await userApi.dispose();
    }

    // Approve the main application (allocation mode=all).
    const allocation = await allocateAccreditationApi(accreditation.id, 'all');
    if (allocation.approved < 1) {
        throw new Error('Wallet setup main allocation approved nobody');
    }

    // Sub-application (requested) — requires the approved main first.
    const subApi = await request.newContext({ baseURL: FRONTEND_BASE_URL });
    try {
        const login = await subApi.post('/api/auth/login', { data: { email: user.email, password: user.password } });
        if (login.status() !== 200) {
            throw new Error(`Wallet setup sub login failed with status ${login.status()}`);
        }
        const apply = await subApi.post(`/api/sub-accreditations/${subAccreditation.id}/apply`);
        if (apply.status() !== 201) {
            throw new Error(`Wallet setup sub apply failed with status ${apply.status()}`);
        }
        await subApi.post('/api/auth/logout');
    } finally {
        await subApi.dispose();
    }

    // Resolve the approved main application (id decides the .pkpass filename)
    // and approve the sub-application — one admin session for both.
    const adminApi = await loginAdminApi();
    let application = null;
    let subApplication = null;
    try {
        const body = await (await adminApi.get(`/api/admin/applications?accreditation_id=${accreditation.id}`)).json();
        const applications = body.data ?? [];
        for (const entry of applications) {
            if (entry.status === 'approved') {
                application = entry;
                break;
            }
        }
        if (!application) {
            throw new Error('Wallet setup found no approved main application');
        }

        const listBody = await (
            await adminApi.get(`/api/admin/sub-applications?sub_accreditation_id=${subAccreditation.id}`)
        ).json();
        const subApplications = listBody.data ?? [];
        if (subApplications.length !== 1) {
            throw new Error(`Wallet setup expected 1 sub-application, got ${subApplications.length}`);
        }
        subApplication = subApplications[0];
        const approve = await adminApi.put(`/api/admin/sub-applications/${subApplication.id}`, {
            data: { status: 'approved' },
        });
        if (approve.status() !== 200) {
            throw new Error(`Wallet setup sub approve failed with status ${approve.status()}`);
        }
    } finally {
        await adminApi.dispose();
    }

    return {
        accreditation,
        application,
        subAccreditation,
        subApplication,
        categoryName,
        user: { email: user.email, password },
    };
}

/**
 * Creates a mandant-scoped blacklist entry via the admin API (super admin /
 * mandant_admin only). Returns the created entry.
 *
 * @returns {Promise<Record<string, unknown>>}
 */
export async function createBlacklistEntryApi(payload = {}) {
    const api = await loginAdminApi();
    try {
        const response = await api.post('/api/admin/blacklists', { data: payload });
        if (response.status() !== 201) {
            throw new Error(`Blacklist create failed with status ${response.status()}`);
        }

        return (await response.json()).data;
    } finally {
        await api.dispose();
    }
}

/**
 * Runs the manual allocation trigger (mode=all | mode=first) on one
 * accreditation via the admin API and returns the `{approved, denied,
 * skipped_blacklist}` result.
 *
 * @returns {Promise<{ approved: number; denied: number; skipped_blacklist: number }>}
 */
export async function allocateAccreditationApi(accreditationId = 0, mode = 'all', limit = undefined) {
    const api = await loginAdminApi();
    try {
        const response = await api.post(`/api/admin/accreditations/${accreditationId}/allocate`, {
            data: limit === undefined ? { mode } : { mode, limit },
        });
        if (response.status() !== 200) {
            throw new Error(`Allocation failed with status ${response.status()}`);
        }

        return (await response.json()).data;
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
/**
 * Removes E2E portal events (titled "Portal-Test *" / competition
 * "E2E Wettbewerb *") for the current primary mandant so repeated runs never
 * accumulate duplicates that break the portal calendar's single-card assertion.
 *
 * This helper is the only producer of these events and is invoked once per run,
 * so removing its own prior artifacts is safe under Playwright's parallel
 * workers. It deliberately does NOT touch the shared "E2E Heimverein" team
 * (created by `ensurePrimaryMandantHasTeam` and consumed by other specs in
 * parallel) — that is reclaimed by the serial global teardown instead.
 */
async function cleanupPrimaryMandantPortalEvents() {
    const api = await loginAdminApi();
    try {
        const body = await (await api.get('/api/admin/events')).json();
        const events = body.data ?? [];
        for (const event of events) {
            const title = event.title ?? '';
            const competition = event.competition ?? '';
            if (title.startsWith('Portal-Test ') || competition.startsWith('E2E Wettbewerb ')) {
                await api.delete(`/api/admin/events/${event.id}`);
            }
        }
    } finally {
        await api.dispose();
    }
}

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

        // Self-cleaning: drop any portal event left by a previous run before
        // creating the deterministic one for this run, so the portal calendar
        // always holds exactly one matching card.
        await cleanupPrimaryMandantPortalEvents();

        const title = `Portal-Test ${Date.now()}`;
        const competition = `E2E Wettbewerb ${Date.now()}`;
        const create = await api.post('/api/admin/events', {
            data: {
                title,
                team_id: team.id,
                date: isoDateInDays(60),
                venue: 'E2E Portal Arena',
                competition,
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

/**
 * Best-effort global purge of every E2E artifact left in the dev database.
 * Intended to run from Playwright's `globalTeardown` so each full run starts
 * from a clean slate, but exported so it can also be invoked manually. Never
 * throws — a down stack or an already-removed row must not fail the suite.
 *
 * The purge is deliberately written with inline loops (rather than param-bearing
 * helpers) so it stays on the plain-ES2020 parser that `tests/e2e` uses while
 * still satisfying the strict `tsc` build (no implicit-`any` parameters).
 */
export async function purgeAllE2EArtifacts() {
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
            throw new Error('No mandant found for E2E purge');
        }
        const primaryId = primary.id;

        // Badge templates: name "E2E Ausweis*".
        const templateBody = await (await api.get('/api/admin/badge-templates')).json();
        for (const template of templateBody.data ?? []) {
            if ((template.name ?? '').startsWith('E2E Ausweis')) {
                await api.delete(`/api/admin/badge-templates/${template.id}`);
            }
        }

        // Categories: "E2E Akkreditierung *" / "E2E Sub Akkreditierung *".
        // Deleting a category cascades to its accreditations (and their
        // applications / sub-accreditations), reclaiming all accreditation data.
        const categoryBody = await (await api.get('/api/admin/categories')).json();
        for (const category of categoryBody.data ?? []) {
            const name = category.name ?? '';
            if (name.startsWith('E2E Akkreditierung ') || name.startsWith('E2E Sub Akkreditierung ')) {
                await api.delete(`/api/admin/categories/${category.id}`);
            }
        }

        // Events: portal markers ("Portal-Test *" / "E2E Wettbewerb *") and
        // accreditation markers ("E2E Akkreditierung Event *" /
        // "E2E Sub Akkreditierung Event *").
        const eventBody = await (await api.get('/api/admin/events')).json();
        for (const event of eventBody.data ?? []) {
            const title = event.title ?? '';
            const competition = event.competition ?? '';
            if (
                title.startsWith('Portal-Test ') ||
                competition.startsWith('E2E Wettbewerb ') ||
                title.startsWith('E2E Akkreditierung Event ') ||
                title.startsWith('E2E Sub Akkreditierung Event ')
            ) {
                await api.delete(`/api/admin/events/${event.id}`);
            }
        }

        // Teams: "E2E Heimverein *" (shared across specs, so reclaimed here).
        const teamBody = await (await api.get(`/api/admin/mandants/${primaryId}/teams`)).json();
        for (const team of teamBody.data ?? []) {
            if ((team.name ?? '').startsWith('E2E Heimverein ')) {
                await api.delete(`/api/admin/mandants/${primaryId}/teams/${team.id}`);
            }
        }

        // Mandants: E2E-prefixed (name "E2E *" / slug "e2e-*") fabricated by
        // admin-mandant.spec. The destroy route refuses to delete a mandant that
        // still owns teams (409), so detach its teams first — they are all E2E
        // test artifacts — then delete the mandant (which cascades to the
        // mandant's own events / categories). The seeded primary mandant
        // ("Hauptseite") is never matched.
        const allMandantsBody = await (await api.get('/api/admin/mandants')).json();
        for (const mandant of allMandantsBody.data ?? []) {
            const name = mandant.name ?? '';
            const slug = mandant.slug ?? '';
            if (name.startsWith('E2E ') || slug.startsWith('e2e-')) {
                const teamBody = await (await api.get(`/api/admin/mandants/${mandant.id}/teams`)).json();
                for (const team of teamBody.data ?? []) {
                    await api.delete(`/api/admin/mandants/${mandant.id}/teams/${team.id}`);
                }
                await api.delete(`/api/admin/mandants/${mandant.id}`);
            }
        }
    } catch (error) {
        console.warn('[e2e-hygiene] purgeAllE2EArtifacts failed:', error);
    } finally {
        await api.dispose();
    }
}
