import { request } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { MailpitHelper } from './mailpit';
import { pngFixture } from '../../screenshots/helpers/png-fixtures';

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
 * Home venue of the shared `E2E Heimverein *` teams, and the venue the portal
 * fixture pins on its own event (`portal.spec.ts` asserts the resolved name
 * "E2E Portal Arena" on the event detail page, so it must stay distinct).
 */
const HOME_VENUE_NAME = 'E2E Heimstadion';
const PORTAL_VENUE_NAME = 'E2E Portal Arena';

/**
 * Name markers under which a SPEC may create a badge template, and therefore
 * everything the serial teardown has to reclaim.
 *
 * ## Why this table exists at all
 *
 * A spec used to sweep its own templates with a `startsWith` prefix in a
 * `test.afterAll` hook. MEASURED (Playwright 1.63, `fullyParallel`, 8 workers):
 * a file's `afterAll` runs once **per worker process that executed a test of
 * that file** — and `test.skip()` inside `beforeEach` suppresses only the test
 * BODY, not the file's `afterAll`. The Mobile Chrome worker therefore "ran" the
 * skipped `badge.spec.ts` test and fired the sweep mid-run: in three
 * consecutive full runs the log showed two `afterAll` invocations in two
 * different pids, the first deleting `511:E2E Ausweis` **while the Desktop
 * Chrome worker was between "create template" and "export"**. The export then
 * answered `422 No badge template.` and the test hung on
 * `waitForEvent('download')` until the test timeout. `badge-editor.spec.ts` had
 * the same hook and fired 6–10 times per run, deleting other workers'
 * in-flight templates.
 *
 * ## The rule this table makes enforceable
 *
 * 1. **A per-worker / per-test cleanup deletes by ID**, never by name prefix —
 *    a row it did not create is not its business.
 * 2. **A name-prefix sweep belongs in ONE place: the serial `globalTeardown`**,
 *    which by construction runs after every test of the run has finished. That
 *    is the only place a prefix can be reclaimed safely, and it is where
 *    crashed-run leftovers die.
 * 3. **Every spec's marker is listed here**, so "what does the teardown
 *    reclaim" has exactly one answer, and a new spec that adds a marker cannot
 *    silently start leaking rows.
 *
 * Rule 1 + 2 is what `tests/e2e/namespace-isolation.spec.ts` pins, together
 * with the same separation the ui-review harness needs against this suite.
 */
export const BADGE_TEMPLATE_PURGE_PREFIXES = ['E2E Ausweis', 'E2E Editor', 'E2E Badge Mobile'];

/**
 * EVERY name marker the serial teardown reclaims, in one object.
 *
 * `purgeAllE2EArtifacts()` below reads its markers from HERE rather than from
 * inline literals, for two reasons:
 *
 * 1. **It is the contract the ui-review harness is checked against.**
 *    `tests/screenshots/helpers/fixture-names.ts` owns that suite's namespace;
 *    `tests/e2e/namespace-isolation.spec.ts` proves the two are disjoint by
 *    comparing the review's names against this table. A second, drifting copy
 *    of the markers inside the loop would make that proof worthless — so there
 *    is no second copy.
 * 2. **A new fixture marker is a visible edit.** Adding a spec's marker here is
 *    the same act as teaching the teardown to reclaim it, which is the coupling
 *    a leftover row needs.
 *
 * Keys are entity kinds, values are the prefixes/prefixes-with-space the teardown
 * matches with `startsWith` (an empty array = the entity is matched by slug).
 */
export const E2E_PURGE_MARKERS = {
    /** `badge_templates.name` */
    badgeTemplateNames: BADGE_TEMPLATE_PURGE_PREFIXES,
    /** `badge_images.original_name` */
    badgeImageNames: ['e2e-'],
    /** `categories.name` */
    categoryNames: ['E2E Akkreditierung ', 'E2E Sub Akkreditierung '],
    /** `events.title` and `events.competition` */
    eventTitles: ['Portal-Test ', 'E2E Akkreditierung Event ', 'E2E Sub Akkreditierung Event '],
    eventCompetitions: ['E2E Wettbewerb '],
    /** `teams.name` */
    teamNames: ['E2E Heimverein '],
    /** `venues.name` */
    venueNames: ['E2E '],
    /** `mandants.name` and `mandants.slug` */
    mandantNames: ['E2E '],
    mandantSlugs: ['e2e-'],
};

/**
 * Ensures both shared E2E venues exist as ACTIVE rows and returns them. Venue
 * master data (W12) is referenced by id, so every fixture that needs a venue
 * must resolve the id first.
 *
 * Reuses an existing venue of that name: the names are deliberately
 * worker-independent (shared fixtures, like the "E2E Heimverein *" teams), and
 * a duplicate `POST` would be refused with a 422 anyway. A name that exists but
 * is INACTIVE is reactivated rather than duplicated — that is the whole point
 * of deactivation instead of deletion.
 *
 * Zero parameters on purpose, for the reason spelled out on `LOGO_LOCK_FILE`
 * below: `tests/e2e` is linted with the PLAIN-JS parser but still built by the
 * strict `tsc -b`, so a parameter type annotation would be an ESLint parse error
 * and an un-annotated one an implicit `any`. Both names are module constants
 * instead, which is why this returns the pair rather than taking a name.
 *
 * @returns {Promise<{ home: { id: number; name: string }, portal: { id: number; name: string } }>}
 */
export async function ensurePrimaryMandantHasVenues() {
    const api = await loginAdminApi();
    try {
        const body = await (await api.get('/api/admin/venues')).json();
        const existing = body.data ?? [];
        const resolved = new Map();

        for (const name of [HOME_VENUE_NAME, PORTAL_VENUE_NAME]) {
            let match = null;
            for (const venue of existing) {
                if (venue.name === name) {
                    match = venue;
                    break;
                }
            }

            if (match !== null && match.is_active) {
                resolved.set(name, match);
                continue;
            }

            if (match !== null) {
                // Deactivated, not deleted: reactivation is the sanctioned way
                // to bring a retired name back, and a second POST would 422.
                const reactivate = await api.put(`/api/admin/venues/${match.id}`, { data: { is_active: true } });
                if (reactivate.status() !== 200) {
                    throw new Error(`Reactivating the setup venue failed with status ${reactivate.status()}`);
                }
                resolved.set(name, (await reactivate.json()).data);
                continue;
            }

            const create = await api.post('/api/admin/venues', { data: { name } });
            if (create.status() !== 201) {
                throw new Error(`Creating the setup venue failed with status ${create.status()}`);
            }
            resolved.set(name, (await create.json()).data);
        }

        return { home: resolved.get(HOME_VENUE_NAME), portal: resolved.get(PORTAL_VENUE_NAME) };
    } finally {
        await api.dispose();
    }
}

/**
 * Ensures the primary mandant (the domain-derived current mandant in local
 * dev) has teams enabled and at least one team with a home venue, so the
 * category/event UI can create team-level rows. Returns the first team.
 *
 * @returns {Promise<{ id: number; name: string; venue: { id: number; name: string } | null, venue_id: number | null }>}
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

        const suffix = uniqueSuffix();
        const { home: homeVenue } = await ensurePrimaryMandantHasVenues();
        const create = await api.post(`/api/admin/mandants/${primary.id}/teams`, {
            data: {
                name: `E2E Heimverein ${suffix}`,
                slug: `e2e-heimverein-${suffix}`,
                venue_id: homeVenue.id,
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

        const suffix = uniqueSuffix();
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

        const suffix = uniqueSuffix();
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
    const suffix = uniqueSuffix();
    const email = `sub-${suffix}@example.test`;
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

        // The portrait is the shared, CRC-verified PROBE from
        // `tests/screenshots/helpers/png-fixtures.ts` (100x100, asymmetric colour
        // bands). The inline literal that used to sit here had a corrupt IDAT
        // chunk (MEASURED: stored 0xfb7d5809, computed 0xfb7d58c9): ImageMagick
        // refuses to decode it and dompdf renders an EMPTY photo box — no error,
        // exit 0, i.e. indistinguishable from "the renderer forgot the photo".
        // Passes the backend's image/dimension validation (max 2000px, no min).
        const portrait = await api.post('/api/user/media', {
            multipart: {
                type: 'portrait',
                file: {
                    name: 'portrait.png',
                    mimeType: 'image/png',
                    buffer: pngFixture('portrait-probe'),
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
 * Worker- AND process-scoped uniqueness stamp for E2E fixture names/slugs.
 *
 * `PORTAL_FIXTURE_KEY` pins the stamp to one worker of one host process
 * (`w<i>-p<pid>`, or `p<pid>` where Playwright exposes no worker index);
 * appending `Date.now()` separates repeated invocations over time. Two
 * concurrent `playwright test` runs, the two browser projects and the
 * `--repeat-each` amplification of a single spec therefore all mint DISTINCT
 * suffixes, so a name/slug built from this value can never collide into a
 * backend 422 or a Playwright strict-mode shared-row violation.
 *
 * Each call site mints the suffix once per test and reuses that single value
 * for its name/slug, so there are no two same-millisecond calls inside one
 * worker to worry about.
 */
export function uniqueSuffix() {
    return `${PORTAL_FIXTURE_KEY}-${Date.now()}`;
}

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
            const suffix = uniqueSuffix();
            const { home: homeVenue } = await ensurePrimaryMandantHasVenues();
            const teamCreate = await api.post(`/api/admin/mandants/${primary.id}/teams`, {
                data: {
                    name: `E2E Heimverein ${suffix}`,
                    slug: `e2e-heimverein-${suffix}`,
                    venue_id: homeVenue.id,
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
        // The portal event pins its OWN venue so the portal fixture does not
        // depend on the shared team's home venue.
        const { portal: portalVenue } = await ensurePrimaryMandantHasVenues();
        const create = await api.post('/api/admin/events', {
            data: {
                title,
                team_id: team.id,
                date: isoDateInDays(60),
                venue_id: portalVenue.id,
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
 * Clears the primary mandant's logo at the START of a run, i.e. the setup-side
 * counterpart of `resetPrimaryMandantLogo()` and the crash insurance for the
 * teardown.
 *
 * M1-Residual: the teardown only runs when the run ends *cleanly*. A hard kill
 * (SIGKILL, CI timeout, closed laptop) between `admin-mandant.spec.ts`'s upload
 * and its removal skips the teardown, and the primary mandant keeps the logo —
 * so the NEXT run inherits it and `portal.spec.ts`'s "static fallback logo"
 * assertion (plus `admin-mandant.spec.ts`'s "Kein Bild hinterlegt." empty state
 * and the screenshot suite's documented baseline) fail until somebody clears
 * the column by hand. Resetting once in `globalSetup` — a fixture step, not part
 * of any assertion — closes that hole without making the assertion
 * self-fulfilling: within a run the mutex
 * (`acquirePrimaryMandantLogoLock()`) still serialises the only two writers, so
 * the assertion keeps testing "the upload window was not active".
 *
 * Strict `is_primary` resolution, deliberately WITHOUT the `mandants[0]`
 * fallback the fixture helpers use: this is a destructive pre-run step, and
 * guessing "the first mandant" when no primary is flagged would delete the
 * logo of an arbitrary OTHER mandant. "No primary mandant" is therefore a
 * logged no-op, never a guess and never an error.
 *
 * Idempotent: `logo_url` is `null` exactly when no file is referenced
 * (`MandantResource`), so a mandant that already has no logo costs a single
 * GET and returns false — calling this on every run is free of noise.
 *
 * @returns {Promise<boolean>} true when a stranded logo was removed.
 */
export async function resetPrimaryMandantLogoForRun() {
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
            console.warn('[e2e-hygiene] no primary mandant — no logo to reset before the run');
            return false;
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

        // Badge templates: the markers every spec registers in
        // `BADGE_TEMPLATE_PURGE_PREFIXES` — the SERIAL teardown is the only
        // place a prefix sweep is safe (see that constant for the measurement
        // that forced the rule).
        const templateBody = await (await api.get('/api/admin/badge-templates')).json();
        for (const template of templateBody.data ?? []) {
            if (E2E_PURGE_MARKERS.badgeTemplateNames.some((marker) => (template.name ?? '').startsWith(marker))) {
                await api.delete(`/api/admin/badge-templates/${template.id}`);
            }
        }

        // Badge images: original_name "e2e-*" (uploaded by the badge-editor
        // upload E2E test). The destroy route removes both the row and the
        // private-disk file, so repeated runs never accumulate orphans.
        const badgeImageBody = await (await api.get('/api/admin/badge-images')).json();
        for (const image of badgeImageBody.data ?? []) {
            if (E2E_PURGE_MARKERS.badgeImageNames.some((marker) => (image.original_name ?? '').startsWith(marker))) {
                await api.delete(`/api/admin/badge-images/${image.id}`);
            }
        }

        // Categories: "E2E Akkreditierung *" / "E2E Sub Akkreditierung *".
        // Deleting a category cascades to its accreditations (and their
        // applications / sub-accreditations), reclaiming all accreditation data.
        const categoryBody = await (await api.get('/api/admin/categories')).json();
        for (const category of categoryBody.data ?? []) {
            if (E2E_PURGE_MARKERS.categoryNames.some((marker) => (category.name ?? '').startsWith(marker))) {
                await api.delete(`/api/admin/categories/${category.id}`);
            }
        }

        // Events: portal markers ("Portal-Test *" / "E2E Wettbewerb *") and
        // accreditation markers ("E2E Akkreditierung Event *" /
        // "E2E Sub Akkreditierung Event *").
        const eventBody = await (await api.get('/api/admin/events')).json();
        for (const event of eventBody.data ?? []) {
            if (
                E2E_PURGE_MARKERS.eventTitles.some((marker) => (event.title ?? '').startsWith(marker)) ||
                E2E_PURGE_MARKERS.eventCompetitions.some((marker) => (event.competition ?? '').startsWith(marker))
            ) {
                await api.delete(`/api/admin/events/${event.id}`);
            }
        }

        // Teams: "E2E Heimverein *" (shared across specs, so reclaimed here).
        const teamBody = await (await api.get(`/api/admin/mandants/${primaryId}/teams`)).json();
        for (const team of teamBody.data ?? []) {
            if (E2E_PURGE_MARKERS.teamNames.some((marker) => (team.name ?? '').startsWith(marker))) {
                await api.delete(`/api/admin/mandants/${primaryId}/teams/${team.id}`);
            }
        }

        // Venues (W12): the shared E2E fixtures. Deleted AFTER the teams and
        // events above, because a referenced venue is refused with a 409 and
        // would otherwise survive every run.
        const venueBody = await (await api.get('/api/admin/venues')).json();
        for (const venue of venueBody.data ?? []) {
            if (E2E_PURGE_MARKERS.venueNames.some((marker) => (venue.name ?? '').startsWith(marker))) {
                await api.delete(`/api/admin/venues/${venue.id}`);
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
            if (
                E2E_PURGE_MARKERS.mandantNames.some((marker) => (mandant.name ?? '').startsWith(marker)) ||
                E2E_PURGE_MARKERS.mandantSlugs.some((marker) => (mandant.slug ?? '').startsWith(marker))
            ) {
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
