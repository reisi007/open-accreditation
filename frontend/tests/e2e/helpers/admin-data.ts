import { request } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { MailpitHelper } from './mailpit';

/**
 * Single source of truth for the origin the E2E **API helpers** drive.
 *
 * The browser navigates via `use.baseURL` in `playwright.config.ts`, which reads
 * the same `E2E_BASE_URL` with the same default. These two MUST agree: a helper
 * that hardcoded `http://localhost:5173` while the browser ran against
 * `E2E_BASE_URL=http://localhost:4173` would drive the browser against one stack
 * and set up its fixtures against ANOTHER — the symptom is a suite that fails
 * with "element not found" for reasons no assertion can explain.
 *
 * `playwright.config.ts` deliberately does NOT import this module (a Playwright
 * config must not pull in test helpers), so the expression is mirrored there.
 * Change one side, change the other.
 */
export const FRONTEND_BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:5173';

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
 * Fixture key of the CURRENT Playwright worker, stamped into every portal
 * fixture's title and competition (`Portal-Test w3-4821 <ts>` /
 * `E2E Wettbewerb w3-4821 <ts>`).
 *
 * Why the key is per WORKER and not per run: Playwright's two projects
 * (`Desktop Chrome`, `Mobile Chrome`) run the SAME test in the SAME run, and a
 * run does not expose a run id to the worker process. A worker, on the other
 * hand, runs exactly one test at a time, so a worker key is precisely the
 * granularity at which "may I delete this fixture?" is answerable without
 * coordination.
 *
 * Why the PID is part of the key and not just the fallback: `TEST_WORKER_INDEX`
 * restarts at 0 in every host process, so two CONCURRENT `playwright test` runs
 * on the same machine (e.g. the screenshot suite next to an E2E run, or two
 * shells) both mint `w0` — and then worker A's self-cleanup deletes worker B's
 * live portal event mid-assertion. Appending the PID makes the key unique per
 * (run, worker) pair. For contexts Playwright gives no worker index to (the
 * global-teardown process) the PID alone is already the key.
 */
const PORTAL_FIXTURE_KEY = (() => {
    const workerIndex = process.env.TEST_WORKER_INDEX;
    return workerIndex === undefined || workerIndex === ''
        ? `p${process.pid}`
        : `w${workerIndex}-p${process.pid}`;
})();

/**
 * Removes THIS worker's own leftover portal fixtures (title
 * "Portal-Test <workerKey> *" / competition "E2E Wettbewerb <workerKey> *") for
 * the current primary mandant, so repeated invocations never accumulate
 * duplicates that break the portal calendar's single-card assertion.
 *
 * WP-9-D4: the previous version deleted EVERY `Portal-Test *` / `E2E Wettbewerb
 * *` event mandant-wide and assumed "this helper is the only producer, so
 * removing prior artifacts is safe". That assumption is false under
 * `fullyParallel`: the Desktop and Mobile projects call this helper at the same
 * time, so the second project's cleanup wiped the first project's event and its
 * assertions died on "element(s) not found" (measured: 7 of 24 slots red,
 * reproducibly). Scoping the delete to the current worker's own marker makes
 * the two projects write-only-disjoint namespaces: neither can delete the
 * other's live fixture, and both still reclaim their own leftovers.
 *
 * Cross-RUN hygiene stays where it belongs: the serial `globalTeardown`
 * (`purgeAllE2EArtifacts`) purges every E2E marker mandant-wide, once, after
 * all tests have finished — it is the only place a mandant-wide delete is safe.
 * It deliberately does NOT touch the shared "E2E Heimverein" team (created by
 * `ensurePrimaryMandantHasTeam` and consumed by other specs in parallel) —
 * that is reclaimed by the same serial teardown.
 */
async function cleanupPrimaryMandantPortalEvents() {
    const api = await loginAdminApi();
    try {
        const body = await (await api.get('/api/admin/events')).json();
        const events = body.data ?? [];
        for (const event of events) {
            const title = event.title ?? '';
            const competition = event.competition ?? '';
            if (
                title.startsWith(`Portal-Test ${PORTAL_FIXTURE_KEY} `) ||
                competition.startsWith(`E2E Wettbewerb ${PORTAL_FIXTURE_KEY} `)
            ) {
                await api.delete(`/api/admin/events/${event.id}`);
            }
        }
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

        // Self-cleaning: drop THIS worker's leftover portal event before creating
        // the deterministic one, so the portal calendar holds exactly one
        // matching card for the competition this test filters by. A concurrently
        // running project keeps its own event (see PORTAL_FIXTURE_KEY).
        await cleanupPrimaryMandantPortalEvents();

        // Title and competition share ONE stamp, so the competition filter the
        // portal spec applies isolates exactly this one card.
        const stamp = `${PORTAL_FIXTURE_KEY} ${Date.now()}`;
        const title = `Portal-Test ${stamp}`;
        const competition = `E2E Wettbewerb ${stamp}`;
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
 * Removes an uploaded logo from the primary mandant and returns whether one was
 * actually there.
 *
 * WHY this exists: `admin-mandant.spec.ts` uploads a logo to the primary mandant
 * and removes it again inside the "self-service logo upload" test. Its `finally`
 * only releases the MUTEX — it cannot un-upload the file. If anything between
 * upload and remove fails (crashed worker, SIGKILL, `maxFailures` abort), the
 * primary mandant keeps the logo, and every later run inherits it: the portal
 * spec's "static fallback logo" assertion and the screenshot suite's documented
 * `admin-media` empty state both silently turn into a filled state. Nothing else
 * in the suite resets that row.
 *
 * Idempotent by construction: `logo_url` is `null` exactly when no file is
 * referenced (`MandantResource`), so the common case is a single GET and no
 * DELETE — no 404-driven noise on every teardown.
 *
 * Scope note: this restores the seeded LOGO state, which is the only media field
 * the suite mutates (no E2E test uploads a mandant header). It is a
 * seeded-state restore, not a name-matched artifact sweep, because the logo
 * carries no E2E marker — and yes, it removes a logo a developer uploaded by
 * hand. That is the intended semantics: the rest of this file's purge deletes
 * E2E fixtures unconditionally too, and a persistent dev DB is not a place to
 * keep manual fixtures that silently change what the suite asserts.
 *
 * @returns {Promise<boolean>} true when a logo was removed.
 */
export async function resetPrimaryMandantLogo() {
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
            throw new Error('No mandant found for the logo reset');
        }
        if (primary.logo_url === null || primary.logo_url === undefined) {
            return false;
        }
        const remove = await api.delete(`/api/admin/mandants/${primary.id}/logo`);
        if (remove.status() !== 204) {
            throw new Error(`Removing the primary mandant logo failed with status ${remove.status()}`);
        }
        return true;
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
    // Restore the primary mandant's seeded logo state FIRST, in its own
    // try/catch: it is the one artifact whose leftover is invisible (no E2E
    // name marker) and therefore the one a crash is most likely to strand —
    // see `resetPrimaryMandantLogo()`. Isolated so a failure here cannot skip
    // the rest of the purge.
    try {
        const removed = await resetPrimaryMandantLogo();
        if (removed) {
            console.log('[e2e-hygiene] removed a leftover primary mandant logo');
        }
    } catch (error) {
        console.warn('[e2e-hygiene] resetPrimaryMandantLogo failed:', error);
    }

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

        // Badge images: original_name "e2e-*" (uploaded by the badge-editor
        // upload E2E test). The destroy route removes both the row and the
        // private-disk file, so repeated runs never accumulate orphans.
        const badgeImageBody = await (await api.get('/api/admin/badge-images')).json();
        for (const image of badgeImageBody.data ?? []) {
            if ((image.original_name ?? '').startsWith('e2e-')) {
                await api.delete(`/api/admin/badge-images/${image.id}`);
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

/**
 * Cross-process mutex for the one piece of global E2E state that two DIFFERENT
 * spec files mutate: the primary mandant's logo.
 *
 * WP-9-D4. `admin-mandant.spec.ts` uploads a logo to the primary mandant (and
 * removes it again), while `portal.spec.ts` asserts that the primary mandant has
 * NO logo and therefore shows the static `/logo.svg` fallback. Both address the
 * same mandant row through the same host-resolved origin, so under
 * `fullyParallel` the portal assertion could land inside the upload window and
 * see the mandant logo. No assertion was weakened: the two tests now take turns
 * on the shared row instead of racing for it.
 *
 * Why a lock file and not `test.describe.serial`: serial mode only orders tests
 * within one file, and Playwright offers no cross-file/cross-project
 * serialisation primitive. The lock is a plain exclusive-create file, which is
 * the standard mutex and works across the worker processes of one machine (local
 * runs, the screenshots suite, and the CI container alike). The contended region
 * is a few seconds of wall clock; the acquisition fails LOUDLY on timeout so a
 * deadlock can never turn into a silently skipped assertion, and a lock left
 * behind by a killed process is stolen once it is older than the stale window.
 *
 * Ownership (why the file CONTENT matters): an `O_EXCL` create alone is not
 * mutual exclusion once stale-stealing is in play. A holder whose lock was
 * stolen would happily `unlink` the NEW holder's file, destroying THEIR lock and
 * letting a third process in. So every lock file carries an owner token and BOTH
 * the release and the steal re-read the file and act only on a lock that still
 * belongs to them. `release()` is a no-op when the file is gone or foreign.
 *
 * Usage (the release must sit in a `finally`, as everywhere else here):
 *
 *   const release = await acquirePrimaryMandantLogoLock();
 *   try { ... } finally { release(); }
 */
const LOGO_LOCK_DIR = path.join(os.tmpdir(), 'open-accreditation-e2e');
const LOGO_LOCK_FILE = path.join(LOGO_LOCK_DIR, 'primary-mandant-logo.lock');

/**
 * How long a waiter keeps polling before it gives up. Must stay ABOVE
 * `LOGO_LOCK_STALE_MS`: a waiter that gives up before a dead holder's lock can
 * go stale can never win the race it is waiting for — it would time out on a
 * lock that was reclaimable three seconds after it stopped looking. Enforced
 * below, not just documented.
 */
const LOGO_LOCK_TIMEOUT_MS = 60000;

/**
 * How old a lock must be before another process may reclaim it. Must stay
 * BELOW `LOGO_LOCK_TIMEOUT_MS` (see there) and above the longest legitimate
 * critical section (a logo upload + removal through the UI: a few seconds), so
 * that a slow-but-alive holder is never robbed of its lock mid-test.
 */
const LOGO_LOCK_STALE_MS = 30000;

if (!(LOGO_LOCK_STALE_MS < LOGO_LOCK_TIMEOUT_MS)) {
    throw new Error(
        `Logo lock invariant violated: LOGO_LOCK_STALE_MS (${LOGO_LOCK_STALE_MS}) must be < LOGO_LOCK_TIMEOUT_MS (${LOGO_LOCK_TIMEOUT_MS})`,
    );
}

/**
 * Owner token of THIS process, written into every lock file it creates. The pid
 * alone is not enough as a global identity — PIDs are reused, and the same
 * worker legitimately re-acquires the lock for every test it runs — so the
 * timestamp+random suffix makes "is this file still MINE?" answerable even after
 * a same-pid successor took over.
 */
const LOGO_LOCK_OWNER_TOKEN = `${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

/**
 * Exclusive-create the lock file and stamp it with this process' owner token.
 * Returns false when it is already held (the normal contended case) and
 * rethrows anything else.
 */
function tryCreateLogoLock() {
    let fd;
    try {
        fd = fs.openSync(LOGO_LOCK_FILE, 'wx');
    } catch (error) {
        if (error instanceof Error && 'code' in error && error.code === 'EEXIST') {
            return false;
        }
        throw error;
    }
    try {
        fs.writeSync(
            fd,
            `owner=${LOGO_LOCK_OWNER_TOKEN} pid=${process.pid} at=${new Date().toISOString()}\n`,
        );
    } finally {
        fs.closeSync(fd);
    }
    return true;
}

/**
 * True when the lock file still carries THIS process' owner token. A missing
 * file (someone else already cleaned it up) reads as "not mine", which is what
 * keeps `release()` idempotent.
 *
 * Zero parameters on purpose: this directory is linted with the PLAIN-JS parser
 * (`eslint.config.js` gives `tests/e2e/**` no TS parser), so a parameter type
 * annotation would be a parse error, and an un-annotated one is an implicit
 * `any` under the strict `tsc -b` build. Every helper in this lock therefore
 * reads the module-level `LOGO_LOCK_FILE` — the same reason
 * `purgeAllE2EArtifacts` uses inline loops.
 */
function logoLockIsOurs() {
    try {
        return fs.readFileSync(LOGO_LOCK_FILE, 'utf8').includes(`owner=${LOGO_LOCK_OWNER_TOKEN}`);
    } catch (error) {
        if (error instanceof Error && 'code' in error && error.code === 'ENOENT') {
            return false;
        }
        throw error;
    }
}

/**
 * Age of the lock file in ms, or `null` when it no longer exists.
 *
 * `null` is the NORMAL contended outcome, not an error: the holder can release
 * between our failed exclusive-create and this stat, and an unguarded
 * `statSync` let that `ENOENT` escape as a crashed test.
 */
function logoLockAgeMs() {
    try {
        return Date.now() - fs.statSync(LOGO_LOCK_FILE).mtimeMs;
    } catch (error) {
        if (error instanceof Error && 'code' in error && error.code === 'ENOENT') {
            return null;
        }
        throw error;
    }
}

/**
 * Unlinks the lock file, idempotently: a lock that is already gone (a
 * stale-lock steal removed it first) is not an error. Written with an explicit
 * errno check instead of `rmSync(…, { force: true })` because the E2E lint rule
 * bans the `force` property in this directory — it exists to stop Playwright's
 * `click({ force: true })`, and a blanket `eslint-disable` is not an option.
 */
function removeLogoLock() {
    try {
        fs.unlinkSync(LOGO_LOCK_FILE);
    } catch (error) {
        if (!(error instanceof Error && 'code' in error && error.code === 'ENOENT')) {
            throw error;
        }
    }
}

/**
 * Reclaims a lock whose holder died. Returns true when the caller may now try
 * to create its own, false when nothing was (or could be) reclaimed.
 *
 * `rename` instead of `unlink` is what makes the claim atomic: between the
 * staleness check and the removal the original holder may well have released
 * and a third process may have created a fresh lock. Renaming it to a private
 * name claims exactly ONE file — whichever one sits at that path at that
 * instant — so the worst case is that this process steals a LIVE lock. The
 * re-check on the RENAMED file catches precisely that: `rename` preserves mtime,
 * so a claimed file that is no longer stale was created by a live process; it is
 * put back (via `link`, which cannot clobber a re-created lock) and the caller
 * keeps waiting. A claimed file that IS still stale was provably written by the
 * dead process: it is unlinked, and the caller may create.
 */
function stealStaleLogoLock() {
    const claimedPath = `${LOGO_LOCK_FILE}.stolen-${LOGO_LOCK_OWNER_TOKEN}`;
    try {
        fs.renameSync(LOGO_LOCK_FILE, claimedPath);
    } catch (error) {
        // The holder released in the meantime — nothing to steal.
        if (error instanceof Error && 'code' in error && error.code === 'ENOENT') {
            return false;
        }
        throw error;
    }

    // The claimed copy carries the dead holder's mtime, so this is a decision
    // about the SAME lock we judged stale — not a fresh look at a new one.
    let claimedAgeMs = null;
    try {
        claimedAgeMs = Date.now() - fs.statSync(claimedPath).mtimeMs;
    } catch (error) {
        if (!(error instanceof Error && 'code' in error && error.code === 'ENOENT')) {
            throw error;
        }
    }

    if (claimedAgeMs !== null && claimedAgeMs <= LOGO_LOCK_STALE_MS) {
        // We claimed a LIVE lock. Put it back so its owner stays excluded — but
        // with `link`, NOT with `rename`: POSIX `rename` silently OVERWRITES an
        // existing destination, so restoring by rename would clobber the lock of
        // whoever re-created the path in the meantime, which is the exact hole
        // this function exists to close. `link` is atomic (the restored file is
        // never a partial read) and fails with EEXIST instead of overwriting.
        try {
            fs.linkSync(claimedPath, LOGO_LOCK_FILE);
            fs.unlinkSync(claimedPath);
        } catch {
            // EEXIST — somebody re-created the path; their lock is authoritative
            // and untouched, so this claim is simply dropped. Also the landing
            // spot for a filesystem without hardlink support, where the claim is
            // dropped as well: the previous owner's lock then leaks to the next
            // stale-steal instead of being clobbered — the conservative failure.
            try {
                fs.unlinkSync(claimedPath);
            } catch {
                // Already gone — nothing left to clean up.
            }
        }
        return false;
    }

    try {
        fs.unlinkSync(claimedPath);
    } catch (error) {
        if (!(error instanceof Error && 'code' in error && error.code === 'ENOENT')) {
            throw error;
        }
    }
    return true;
}

/**
 * Waits for exclusive ownership of the primary mandant's logo state and returns
 * the release function. Throws instead of degrading if the lock cannot be taken
 * within `LOGO_LOCK_TIMEOUT_MS`.
 *
 * The returned release verifies the owner token before unlinking, so a holder
 * whose lock was stolen in the meantime cannot delete its successor's lock.
 * (Residual, and deliberately not over-engineered away: a steal can only happen
 * after `LOGO_LOCK_STALE_MS`, so for that window to overlap the holder's own
 * release its critical section would have to exceed the stale window — a
 * ~30 s logo upload. There is no atomic compare-and-unlink in POSIX; the token
 * check shrinks the window from "the whole hold" to "one syscall".)
 */
export async function acquirePrimaryMandantLogoLock() {
    fs.mkdirSync(LOGO_LOCK_DIR, { recursive: true });
    const deadline = Date.now() + LOGO_LOCK_TIMEOUT_MS;
    for (;;) {
        if (tryCreateLogoLock()) {
            return () => {
                if (!logoLockIsOurs()) {
                    // Gone, or stolen and re-taken by somebody else — unlinking
                    // now would break THEIR mutual exclusion.
                    return;
                }
                removeLogoLock();
            };
        }
        // Held by someone else. A lock older than the stale window belongs to a
        // process that died mid-test, so it is reclaimed instead of wedging the
        // whole suite. `null` age = the holder released in the meantime → just
        // retry the create.
        const lockAgeMs = logoLockAgeMs();
        if (lockAgeMs !== null && lockAgeMs > LOGO_LOCK_STALE_MS) {
            console.warn(`[e2e-hygiene] stealing stale logo lock ${LOGO_LOCK_FILE} (age ${lockAgeMs}ms)`);
            stealStaleLogoLock();
            continue;
        }
        if (Date.now() > deadline) {
            throw new Error(
                `Timed out after ${LOGO_LOCK_TIMEOUT_MS}ms waiting for the primary-mandant-logo E2E lock (${LOGO_LOCK_FILE})`,
            );
        }
        await new Promise((resolve) => {
            setTimeout(resolve, 100);
        });
    }
}
