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
 * 3. **Every stamped name this suite constructs is listed here**, so "what does
 *    the teardown reclaim" has exactly one answer.
 *
 * ## Rule 3 used to be a CLAIM, and was measurably false
 *
 * This docblock asserted that "a new spec that adds a marker cannot silently
 * start leaking rows", and nothing in the repo checked that. F1 measured the
 * consequence on this machine: **54 leaked `teams` rows and 53 leaked
 * `venues`**, every one of them `E2E %` and none of them `E2E Heimverein %`.
 * `teamNames` listed only `E2E Heimverein `, but three specs create
 * `E2E Team ${suffix}` (`admin-mandant.spec.ts:24`, `admin-venue.spec.ts:84`)
 * and `E2E Team Kategorie ${suffix}` (`admin-category.spec.ts:17`). The purge
 * therefore never even attempted those teams; each of them referenced one
 * `E2E Heimstadion *` venue, and the venue sweep — which DID match, via
 * `venueNames: ['E2E ']` — got a **409** per row, which nothing looked at.
 *
 * Two corrections to the claim's own diagnosis, both measured:
 *
 * - `E2E Team Kategorie ` is a **category** name, not a team name: it is typed
 *   into the category form's Name field for the team-level override
 *   (`admin-category.spec.ts:58`). It belongs in `categoryNames`, and it is
 *   registered there — not because it leaks today (that spec deletes it by id)
 *   but because a crash mid-test is exactly what the serial teardown exists for.
 * - The `venueNames` marker was never wrong. `E2E Heimstadion *` matches
 *   `venueNames: ['E2E ']`; the venue sweep ran, was refused, and the refusal
 *   was discarded. **The missing marker was the teams; the missing status check
 *   is why that stayed invisible.**
 *
 * So rule 3 is now a TEST rather than a sentence, and it is the test that asks
 * the question the old one could not: `namespace-isolation.spec.ts` walks every
 * `.ts` under `tests/e2e`, collects the name prefixes this suite constructs, and
 * holds each of them against THIS table. It fails on a prefix nobody registered.
 * Rule 1 + 2 is what that same file pins, together with the separation the
 * ui-review harness needs against this suite.
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
 *    a leftover row needs. And the entry is not optional bookkeeping: the
 *    coverage test in `namespace-isolation.spec.ts` reads the names the specs
 *    construct and fails on any prefix this object does not match.
 *
 * Keys are entity kinds, values are the prefixes/prefixes-with-space the teardown
 * matches with `startsWith` (an empty array = the entity is matched by slug).
 *
 * ## Blanket markers, and the one rule that permits them
 *
 * Two entries are still a prefix sweep: `badgeImageNames: ['e2e-']` and
 * `mandantSlugs: ['e2e-']`. Both are legal for the same reason — **their column
 * belongs to exactly one swept collection** (`badge_images.original_name` and
 * `mandants.slug`), so a prefix match there cannot reach a row of any other kind.
 * `venueNames` and `mandantNames` were blanket too, and were made explicit: their
 * column is the same `name` column every other kind is matched on, so `'E2E '`
 * there meant "every E2E row of any kind is mine" — which is how the missing team
 * marker stayed invisible, and how the coverage test was made unable to fail.
 *
 * The coverage test in `namespace-isolation.spec.ts` does not care which style a
 * kind uses — it only asks whether each constructed name is matched by SOME
 * marker. Making the name markers explicit is what gives that question an answer.
 * The per-kind accuracy (a team marker filed under `venueNames` would match
 * nothing) is a separate property, pinned by executing `E2E_PURGE_SWEEPS` in the
 * same spec — see that constant.
 */
export const E2E_PURGE_MARKERS = {
    /** `badge_templates.name` */
    badgeTemplateNames: BADGE_TEMPLATE_PURGE_PREFIXES,
    /** `badge_images.original_name` */
    badgeImageNames: ['e2e-'],
    /**
     * `categories.name`. The last two are the admin CRUD specs' own rows:
     * `E2E Kategorie ${suffix}` (mandant-level) and `E2E Team Kategorie
     * ${suffix}` — which despite the name is a CATEGORY name, typed into the
     * category form for the team-level override, not a team.
     */
    categoryNames: [
        'E2E Akkreditierung ',
        'E2E Sub Akkreditierung ',
        'E2E Kategorie ',
        'E2E Team Kategorie ',
    ],
    /**
     * `events.title` and `events.competition`.
     *
     * `E2E Event ` is `admin-event.spec.ts`'s own event; the portal and
     * accreditation fixtures are the others. The competition column is matched
     * separately, because a row can carry an E2E competition with a title that
     * says nothing E2E-ish.
     */
    eventTitles: [
        'Portal-Test ',
        'E2E Akkreditierung Event ',
        'E2E Sub Akkreditierung Event ',
        'E2E Event ',
    ],
    eventCompetitions: ['E2E Wettbewerb '],
    /**
     * `teams.name`. `E2E Team ` is the one the table was missing for F1 — it is
     * created by `admin-mandant.spec.ts` and `admin-venue.spec.ts` and, because
     * neither deletes it, it was the row that 409'd 53 venue deletions.
     */
    teamNames: ['E2E Heimverein ', 'E2E Team '],
    /**
     * `venues.name` — every venue the suite creates, EXPLICITLY.
     *
     * This used to be the blanket `'E2E '`, and that one entry is the root of
     * F1. A blanket marker does two harmful things at once:
     *
     * 1. **It makes the coverage test vacuous.** "Is this E2E name registered
     *    SOMEWHERE?" is answered by `'E2E '` for every name in the suite, so the
     *    check that was supposed to catch a missing marker could not fail —
     *    MEASURED: a probe spec constructing `E2E MUTATION Sonstiges ${suffix}`
     *    left the guard green.
     * 2. **It invites the cross-namespace deletion the whole file exists to
     *    prevent**, because it asserts "every `E2E …` venue is mine" rather
     *    than "these four venues are mine".
     *
     * `E2E Heimstadion` has no trailing space on purpose: the shared fixture
     * constant (`HOME_VENUE_NAME`) is the bare name, while `admin-venue.spec.ts`
     * appends a per-run suffix, and one marker without the space covers both.
     */
    venueNames: ['E2E Heimstadion', 'E2E Portal Arena', 'E2E Spielort '],
    /**
     * `mandants.name` and `mandants.slug` — likewise explicit.
     *
     * The slug list stays the blanket `e2e-`, and that asymmetry is deliberate
     * rather than lazy: a marker may be a prefix-sweep only when its column
     * belongs to ONE swept collection. `mandants.slug` is such a column (nothing
     * else in the table is matched by slug), so nothing outside the E2E mandant
     * namespace can be reached through it. `mandants.name` is shared with teams,
     * venues, categories, events and badge templates — which is exactly why a
     * blanket marker there was both unsafe and untestable.
     */
    mandantNames: ['E2E Mandant ', 'E2E Liste ', 'E2E Switcher Inaktiv ', 'E2E Switcher Ohne Domain '],
    mandantSlugs: ['e2e-'],
};

/**
 * The purge's sweep, as DATA: which collections it walks, which marker list is
 * matched against which column of each, and IN WHICH ORDER.
 *
 * ## Why the order is in the table and not in the code
 *
 * A referenced venue is refused with a **409** naming the reference
 * (`VenueController::deleteVenue`), and a mandant that still owns teams is
 * refused with a **409** too (`MandantController::destroy`). So the order is a
 * correctness requirement, not a style choice: children before parents, or rows
 * survive every run and nothing says so. The order is therefore DATA here, and
 * the coverage test can assert it — an order that put venues before teams would
 * go red in the same spec that pins the markers.
 *
 * ## Why this is not a second copy of anything
 *
 * `purgeAllE2EArtifacts()` iterates THIS array. It holds no marker and no list
 * endpoint of its own; the only branch it takes is `teams` (nested under the
 * mandant) and `mandants` (detach teams first), which is a URL shape, not a
 * matching rule. `E2E_PURGE_MARKERS` above stays the single source of the
 * markers and this array the single source of which collection is compared
 * against which marker — and a test that walks both walks the real plan.
 *
 * `resource` is the API path segment (`/api/admin/{resource}`), which is why
 * `teams` is spelled out rather than nested: only its list and delete URLs need
 * the mandant id.
 */
export const E2E_PURGE_SWEEPS = [
    { resource: 'badge-templates', matchers: [{ field: 'name', markerKind: 'badgeTemplateNames' }] },
    { resource: 'badge-images', matchers: [{ field: 'original_name', markerKind: 'badgeImageNames' }] },
    { resource: 'categories', matchers: [{ field: 'name', markerKind: 'categoryNames' }] },
    {
        resource: 'events',
        matchers: [
            { field: 'title', markerKind: 'eventTitles' },
            { field: 'competition', markerKind: 'eventCompetitions' },
        ],
    },
    { resource: 'teams', matchers: [{ field: 'name', markerKind: 'teamNames' }] },
    { resource: 'venues', matchers: [{ field: 'name', markerKind: 'venueNames' }] },
    {
        resource: 'mandants',
        matchers: [
            { field: 'name', markerKind: 'mandantNames' },
            { field: 'slug', markerKind: 'mandantSlugs' },
        ],
    },
];

/**
 * The marker table as `[kind, markers]` pairs.
 *
 * Exported so the coverage test reads the table through the SAME access the purge
 * does. `Object.entries` rather than `E2E_PURGE_MARKERS[kind]`: the kinds are
 * dynamic (they come from the sweep table above), and indexing a fixed object type
 * with a `string` is a hard `tsc` error in this directory — which is why this
 * indirection exists rather than a helper function. A helper would need
 * parameters, and this directory allows none (see the lock helpers below).
 */
export const E2E_PURGE_MARKER_ENTRIES = Object.entries(E2E_PURGE_MARKERS);

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
 * A row the purge MATCHED and could not reclaim.
 *
 * A separate class from a plain `Error` because the two must be treated
 * differently by the caller, and conflating them is what made F1 invisible:
 * `purgeAllE2EArtifacts` wrapped its whole body in one
 * `try { … } catch { console.warn }`, so a **409 on 53 venue deletes** and a
 * backend that is simply not running produced the same shrug. They are not the
 * same event. A backend that is down must not fail a run that would otherwise
 * report on its own merits; a matched row that survives its own delete means the
 * next run inherits it, and that is the bug this whole mechanism exists to
 * prevent — so it fails the run loudly, with every failed row listed.
 */
class PurgeReclamationFailure extends Error {}

/**
 * Exported so `global-teardown.ts` can tell the two failure classes apart with
 * `instanceof` rather than by calling a predicate helper — a helper would need a
 * parameter, and this directory allows none. `instanceof` is also the more honest
 * form of the test: the class IS the distinction, rather than a function that
 * re-derives it.
 */
export { PurgeReclamationFailure };


/**
 * DELETE with the status checked, and the check made LOUD.
 *
 * F1: the venue loop issued `await api.delete(…)` and threw the answer away,
 * which is why 53 refused deletions produced no output at all. Every DELETE the
 * purge issues now goes through the single status-checked loop at the bottom of
 * `purgeAllE2EArtifacts()`, and an unexpected status is a `PurgeReclamationFailure`.
 *
 * 204 is the contract of every destroy route in `Api/Admin`; 404 is tolerated
 * because two sweeps can name the same row (a mandant is matched by name AND by
 * slug, and the teams/events of a swept mandant are already gone by then), so
 * the second attempt is a no-op rather than a fault. 409 is NOT tolerated
 * anywhere: it is the backend saying "something still references this", which is
 * precisely the ordering bug this must surface.
 */

/**
 * Best-effort global purge of every E2E artifact left in the dev database.
 * Intended to run from Playwright's `globalTeardown` so each full run starts
 * from a clean slate, but exported so it can also be invoked manually.
 *
 * ## What "best-effort" means here, precisely
 *
 * Two failure classes, no longer merged:
 *
 * - **Infrastructure** (the stack is down, no mandant exists, a list request
 *   fails): logged, and thrown as a plain `Error` that `globalTeardown` warns
 *   about. A run must not be failed for a database that is not there.
 * - **Reclamation** (a row we matched answered something other than 204/404):
 *   collected and thrown together as one `PurgeReclamationFailure`, which
 *   `globalTeardown` lets propagate so the run is RED. This is the F1 class —
 *   silent accumulation, one row per run, forever.
 *
 * ## Plan first, then delete — and why that shape
 *
 * The sweep only ever DECIDES here: it walks `E2E_PURGE_SWEEPS`, and for every
 * row a marker matches it appends `{ url, what }` to a plan. The plan is executed
 * by one loop afterwards, which is where the status check lives.
 *
 * That split exists for two reasons, one of them load-bearing:
 *
 * 1. **One status check instead of three.** The mandant sweep has to delete a
 *    mandant's teams before the mandant, and both are DELETEs. With the deletes
 *    inline there would be three copies of the check, and a check that exists
 *    three times is a check that will one day exist twice.
 * 2. **The order is visible in the plan, not implied by nesting.** The mandant's
 *    team deletes are pushed before its own, because the loop pushes them first —
 *    and because the mandant sweep is last in `E2E_PURGE_SWEEPS`, the 409 that
 *    ordering exists to avoid cannot happen.
 *
 * The matching rule (`startsWith` over the sweep's marker kinds) is written out
 * here rather than in a shared helper, because a helper needs parameters and this
 * directory allows none. What stays single-source is the part that actually broke:
 * `E2E_PURGE_MARKERS` (which markers) and `E2E_PURGE_SWEEPS` (which marker against
 * which column of which collection). The one-line comparison is restated in the
 * coverage spec, and that spec is what pins the wiring.
 */
export async function purgeAllE2EArtifacts() {
    // Restore the primary mandant's seeded logo state FIRST, in its own
    // try/catch: it is the one artifact whose leftover is invisible (no E2E
    // name marker) and therefore the one a crash is most likely to strand —
    // see `resetPrimaryMandantLogo()`. Isolated so a failure here cannot skip
    // the rest of the purge.
    //
    // Under the SAME mutex as every other logo writer, including the review
    // harness's (`dataset.ts`). This teardown is serial, so the lock is
    // uncontended and free; the alternative was an exception to the rule the
    // logo-mutex guard in `namespace-isolation.spec.ts` enforces, and an
    // exception is how "only the teardown may do this unlocked" quietly becomes
    // "anybody may do this unlocked". The guard flags THIS call site, which is
    // how that rule was found to be missing a writer.
    let releaseLogoLock;
    try {
        releaseLogoLock = await acquirePrimaryMandantLogoLock();
        const removed = await resetPrimaryMandantLogo();
        if (removed) {
            console.log('[e2e-hygiene] removed a leftover primary mandant logo');
        }
    } catch (error) {
        console.warn('[e2e-hygiene] resetPrimaryMandantLogo failed:', error);
    } finally {
        if (releaseLogoLock !== undefined) {
            releaseLogoLock();
        }
    }

    const api = await loginAdminApi();
    const reclamationFailures = [];
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

        /** The deletions the sweep decided on, in the order they must happen. */
        const plan = [];

        for (const sweep of E2E_PURGE_SWEEPS) {
            const listUrl =
                sweep.resource === 'teams'
                    ? `/api/admin/mandants/${primaryId}/teams`
                    : `/api/admin/${sweep.resource}`;
            const body = await (await api.get(listUrl)).json();
            const rows = body.data ?? [];

            for (const row of rows) {
                // The matching rule. `startsWith`, over the markers of every
                // column this sweep reads; a null column (an event without a
                // competition) is simply not a match.
                let isOurs = false;
                for (const matcher of sweep.matchers) {
                    const value = row[matcher.field];
                    if (value === null || value === undefined) {
                        continue;
                    }
                    const text = String(value);
                    for (const [kind, markers] of E2E_PURGE_MARKER_ENTRIES) {
                        if (kind !== matcher.markerKind) {
                            continue;
                        }
                        for (const marker of markers) {
                            if (text.startsWith(marker)) {
                                isOurs = true;
                            }
                        }
                    }
                }
                if (!isOurs) {
                    continue;
                }

                const what = `purging ${sweep.resource} "${String(row[sweep.matchers[0].field])}" (id ${row.id})`;

                if (sweep.resource === 'mandants') {
                    // Detach first: the destroy route refuses a mandant that
                    // still owns teams with a 409. Every team of an E2E mandant
                    // is an E2E artifact by construction — the mandant only
                    // exists because a spec created it.
                    const owned = await (await api.get(`/api/admin/mandants/${row.id}/teams`)).json();
                    for (const team of owned.data ?? []) {
                        plan.push({
                            url: `/api/admin/mandants/${row.id}/teams/${team.id}`,
                            what: `detaching team "${team.name}" from ${what}`,
                        });
                    }
                }

                plan.push({
                    url:
                        sweep.resource === 'teams'
                            ? `/api/admin/mandants/${primaryId}/teams/${row.id}`
                            : `/api/admin/${sweep.resource}/${row.id}`,
                    what,
                });
            }
        }

        // The one status check. 204 is every destroy route's contract; 404 means a
        // row two sweeps both named is already gone, which is a no-op rather than
        // a fault; anything else — 409 above all — means a row we MATCHED is
        // still there, and that is collected and reported, never swallowed.
        for (const entry of plan) {
            const response = await api.delete(entry.url);
            const status = response.status();
            if (status === 204 || status === 404) {
                continue;
            }
            let body = '';
            try {
                body = (await response.text()).slice(0, 300);
            } catch {
                body = '<unreadable body>';
            }
            reclamationFailures.push(
                `${entry.what}: DELETE ${entry.url} answered ${status} (expected 204/404) — the row ` +
                    `stays and the NEXT run inherits it. Body: ${body}`,
            );
        }
    } catch (error) {
        console.warn('[e2e-hygiene] purgeAllE2EArtifacts failed:', error);
        if (reclamationFailures.length > 0) {
            // Never swallow a reclamation failure behind an infrastructure one:
            // both happened, and the reclamation one is the one that leaves rows
            // behind for the next run.
            throw new PurgeReclamationFailure(
                `${reclamationFailures.length} row(s) survived the purge AND the sweep itself failed: ` +
                    `${error instanceof Error ? error.message : error}\n${reclamationFailures.join('\n')}`,
            );
        }
        throw error;
    } finally {
        await api.dispose();
    }

    if (reclamationFailures.length > 0) {
        throw new PurgeReclamationFailure(
            `[e2e-hygiene] the purge matched ${reclamationFailures.length} row(s) it could not reclaim. ` +
                `Each one survives into the next run — this is the F1 failure mode, and it is not a warning:\n` +
                reclamationFailures.join('\n'),
        );
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
