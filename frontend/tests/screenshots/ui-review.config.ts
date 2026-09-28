import { CAPTURE_STORE_DIR } from './helpers/store-paths';
import {
    seedAccreditation,
    seedApplyFilled,
    seedApprovedApplicationCached,
    seedBadgeTemplate,
    seedBadgeTemplateSchemaV2,
    seedFreigabenFilled,
    seedMyAccreditationsFilled,
    seedPortalEvent,
    seedPrimaryMandant,
    seedUserWithoutApplication,
    seedUsersFilled,
} from './helpers/seeds';

/**
 * UI-review route manifest — the single source of truth for which pages get
 * screenshotted and in which states. Edit this file to add/remove routes; the
 * generic spec (`ui-screenshots.spec.ts`) picks the changes up automatically.
 *
 * ## Where the data comes from
 *
 * Every seed is a SELECTOR over one dataset that `helpers/dataset.ts` builds
 * once per run (reset + find-or-create fixtures under stable names). That is what
 * makes a capture comparable to the previous one: measured before the change,
 * three runs of unchanged code produced 35 → 45 → 66 section bands because each
 * run added rows. The route notes below therefore describe a fixture whose
 * content is fixed, not "whatever the dev DB happens to hold".
 *
 * State semantics:
 * - `filled` — the run's fixed dataset.
 * - `empty`  — the fixture tenant (`empty.localhost`, see
 *   `helpers/empty-mandant.ts`) that resolves to a data-less mandant. A global
 *   super_admin login grants admin-page access there, so no per-tenant user is
 *   needed. Routes whose empty state needs the PRIMARY tenant instead (pages
 *   whose "empty" comes from the logged-in user's own data) override `tenant`.
 *   Routes whose empty state is not meaningful (forms, global super-admin
 *   surfaces) document that in `note`.
 *
 * F6 root cause (why `emptyMock` exists): the `empty.localhost` fixture does
 * NOT resolve in local dev. `MandantContextMiddleware` honors only the
 * `localhost:5173` Referer host and otherwise resolves from the request Host —
 * which the Vite proxy (`changeOrigin`) rewrites to the backend's `localhost`,
 * i.e. the PRIMARY mandant's domain. Every local-dev request therefore
 * resolves to the primary mandant, and "empty" captures of mandant-scoped
 * lists were byte-identical to the filled ones. Routes that must show a
 * GENUINELY empty UI state stub the response via `emptyMock` (`page.route` →
 *   `{data: []}`, or a custom body for non-list endpoints such as
 *   `/api/portal/overview`) and run on the primary tenant; the real fix is
 * a backend change (accept `*.localhost:5173` Referer hosts) — out of frontend
 * scope. This covers admin lists AND public guest routes (home/akkreditierungen).
 */

export type UiReviewState = 'filled' | 'empty';
export type UiReviewViewport = 'desktop' | 'mobile';
export type UiReviewAuth = 'guest' | 'admin' | 'user' | 'none';
export type UiReviewTenant = 'primary' | 'empty';

/**
 * One empty-state API stub. Either a plain URL glob fulfilled with the default
 * `{data: []}`, or an object with a custom JSON `body` for endpoints whose
 * shape is not a bare list (e.g. `/api/portal/overview` answers
 * `{data: {mandant, teams}}`).
 */
export type UiReviewEmptyMock = string | { pattern: string; body?: unknown };

export interface UiReviewClickStep {
    kind: 'click';
    /** Semantic landmark to scope the click to (header, admin sidebar, main content). */
    scope: 'banner' | 'complementary' | 'main';
    role: 'link' | 'button';
    /** Exact accessible name; omit to click the first matching element (e.g. the first list card). */
    name?: string;
    /**
     * Seed key whose value IS the exact accessible name. Preferable to `name`
     * whenever the name is a fixture value rather than a UI constant: it keeps
     * the fixture name in `fixture-names.ts` (where the namespace invariants
     * live) instead of duplicating it in the manifest.
     *
     * It also exists because the alternative was measured: the `events-detail`
     * step used a bare `role: 'link'` click, which only ever worked through
     * `.first()` — and the portal calendar shows three events, so that click
     * picked whichever the list happened to render first. An exact name turns
     * that silent dependency into a strict-mode failure when it stops matching.
     */
    nameFrom?: string;
    /**
     * Restrict to a container (`article` card or table `row`) that holds an
     * element whose ACCESSIBLE NAME IS EXACTLY the seed value for this key.
     *
     * Exactness is the whole point, and it is not a stylistic preference:
     * MEASURED, `hasText` (a substring match) resolved `E2E Akkreditierung
     * ui-review` against BOTH `… ui-review` and `… ui-review Druck`, the page
     * sorts `b.id - a.id`, and the desktop capture therefore clicked the Druck
     * row (id 201) while the mobile capture — which loads the route by URL —
     * showed id 200. Two artifacts of one route, two datasets, nothing in the
     * `.meta.json` to say so. `exact: true` on the inner role locator makes a
     * second name unreachable by construction, and the `withinRole` below says
     * WHICH element carries the name: an `article` card announces its category
     * in a heading, a table row in a cell, and matching on the wrong one is how
     * a "harmless" substring crept in.
     */
    within?: string;
    /** Role of the element that carries the `within` name. */
    withinRole?: 'heading' | 'cell';
    /**
     * Role of the CONTAINER the click is scoped to. Omitted means "an `article`
     * card or a table `row` that SCOPES the click", which is what the two
     * list-shaped routes need.
     *
     * Naming it means the container IS the control and is clicked itself. `link`
     * exists for the portal calendar, where the clickable card is an `<a>`
     * wrapping the whole card and its accessible name is the card's full text
     * (`"… Noch 30 Tage Datum … Ort … Wettbewerb …"`). MEASURED: a
     * `getByRole('link', { name: title })` never matches that, and the title is
     * only addressable as the heading INSIDE the link.
     */
    withinContainer?: 'article' | 'row' | 'link';
}

export interface UiReviewGotoStep {
    kind: 'goto';
    /** May contain `:param` placeholders resolved from the seed result. */
    path: string;
    /** Why a direct URL load is justified (deep link / dynamic detail page / no UI entry point). */
    reason: string;
}

export type UiReviewNavStep = UiReviewClickStep | UiReviewGotoStep;

export type UiReviewSeed = () => Promise<Record<string, unknown>>;

export interface UiReviewRoute {
    name: string;
    /** Route pattern; `:param` tokens are resolved from the seed result where used. */
    path: string;
    states: UiReviewState[];
    auth?: UiReviewAuth;
    viewports?: UiReviewViewport[];
    /** Tenant per state; default: filled → primary, empty → empty. */
    tenant?: Partial<Record<UiReviewState, UiReviewTenant>>;
    note?: string;
    /** Seed per state — only states that need deterministic data define one. */
    seeds?: Partial<Record<UiReviewState, UiReviewSeed>>;
    nav?: UiReviewNavStep[];
    /**
     * URL globs intercepted ONLY in the `empty` state and fulfilled with an
     * empty payload (`{data: []}` by default, custom `body` for non-list
     * endpoints) — used when the `empty.localhost` fixture cannot deliver a
     * genuinely empty capture (see the module header for the F6 root cause:
     * local-dev host resolution always lands on the primary mandant). The
     * empty UI state is then simulated at the API boundary instead of relying
     * on the unreachable fixture tenant. Applies to mandant-scoped admin
     * lists AND public guest routes (portal overview/events, accreditations).
     */
    emptyMock?: UiReviewEmptyMock[];
}

export interface UiReviewConfig {
    /**
     * Root of the capture store, built as absolute paths by `helpers/capture-store`.
     *
     * MUST NOT be inside the Playwright `outputDir` of
     * `playwright.screenshots.config.ts` — and MEASURED TWICE, "not inside
     * `test-results/` at all" is the version that holds. Playwright deletes that
     * directory recursively before the first test of every run: with the store
     * inside `outputDir`, a partial re-capture (`-g "screenshot home"`) took it
     * from 126 PNG to 11, and after moving it next to `outputDir` a
     * `--output=test-results` still took it from 192 to 0, because the runner
     * wipes before `globalSetup` gets a say. The store therefore lives in
     * `test-artifacts/ui-review/`, declared once in `helpers/store-paths.ts`
     * together with the scratch path so the two cannot drift apart again.
     */
    outputDir: string;
    /**
     * Fraction of the viewport height one section capture advances (AGENTS.md
     * §7: "Scroll in 80-%-Schritten"). A fraction, not a pixel count: the two
     * viewports have different heights (950 px desktop, 1040 px mobile —
     * `Galaxy A55`), and a fixed pixel step would cover the same *content* on
     * one and not on the other. The remaining 20 % is overlap on purpose — it is
     * what guarantees that no band of content can fall between two captures.
     */
    sectionScrollStep: number;
    routes: UiReviewRoute[];
}

export const uiReviewConfig: UiReviewConfig = {
    outputDir: CAPTURE_STORE_DIR,
    sectionScrollStep: 0.8,
    routes: [
        // ── Public / guest ──────────────────────────────────────────────────
        {
            name: 'home',
            path: '/',
            states: ['filled', 'empty'],
            auth: 'guest',
            tenant: { empty: 'primary' },
            seeds: { filled: seedPortalEvent },
            emptyMock: [
                '**/api/portal/events*',
                {
                    pattern: '**/api/portal/overview*',
                    body: {
                        data: {
                            mandant: {
                                id: 1,
                                slug: 'empty',
                                name: 'Leerer Mandant',
                                logo_url: null,
                                header_url: null,
                                impressum_text: null,
                                privacy_text: null,
                                teams_enabled: false,
                            },
                            teams: [],
                        },
                    },
                },
            ],
            note: 'Public guest page. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the portal overview (an empty mandant without logo/header/teams) and the events list ([]) via emptyMock and runs on the primary tenant — the homepage renders a genuine empty portal ("Keine Veranstaltungen") instead of a load error or the filled primary data. CORRECTED 2026-09-28: this note used to claim "exactly ONE calendar event … so the page height is fixed". Both halves were false. The calendar renders all THREE dataset events, and the page height is dominated by the mandant\'s TEAM list, which this harness does not own: MEASURED 50 teams (45 `E2E *` leftovers from the functional suite, 4 from other dev work, 1 the review\'s own) → 3016 px desktop / 5462 px mobile → 11 bands. The review\'s contribution is exactly one team, so the band count of THIS route moves whenever the functional suite leaves teams behind — a property of the shared dev database, not of the fixture. The mandant has no uploaded logo (the dataset reset removes one, like the E2E global teardown), so the static fallback is shown.',
        },
        {
            name: 'akkreditierungen',
            path: '/akkreditierungen',
            states: ['filled', 'empty'],
            auth: 'guest',
            tenant: { empty: 'primary' },
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Akkreditierungen' }],
            seeds: { filled: seedAccreditation },
            emptyMock: ['**/api/accreditations*'],
            note: 'Public guest page. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the accreditations list ([]) via emptyMock and runs on the primary tenant — the page renders its genuine empty state ("Keine Akkreditierungen verfügbar.").',
        },
        {
            name: 'login',
            path: '/login',
            states: ['filled', 'empty'],
            auth: 'guest',
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Anmelden' }],
            note: 'Pure form, no data dependency — filled and empty render identically; captured on both mandants for completeness.',
        },
        {
            name: 'verify',
            path: '/verify',
            states: ['filled', 'empty'],
            auth: 'guest',
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Verifizieren' }],
        },
        {
            name: 'verify-token',
            path: '/verify/:token',
            states: ['filled'],
            auth: 'guest',
            viewports: ['desktop'],
            nav: [
                {
                    kind: 'goto',
                    path: '/verify/:token',
                    reason: 'Approved-application QR links have no UI entry point — the token comes from one of the dataset\'s approved applications (the same two the print check renders), a justified direct-URL load.',
                },
            ],
            seeds: { filled: seedApprovedApplicationCached },
        },
        {
            name: 'events-detail',
            path: '/events/:eventId',
            states: ['filled'],
            auth: 'guest',
            nav: [
                {
                    kind: 'click',
                    scope: 'main',
                    role: 'link',
                    within: 'eventName',
                    withinRole: 'heading',
                    withinContainer: 'link',
                },
            ],
            seeds: { filled: seedPortalEvent },
            note: 'The portal calendar lists EVERY event of the mandant — three of them in the dataset — so the card is addressed by the heading inside it that carries the event title EXACTLY. A bare `role: link` click only ever worked through `.first()` and therefore followed the list order, which is a data-dependent choice dressed up as a step; `role: link` + the title as a NAME does not work either, because the calendar card\'s accessible name is its whole text (measured).',
        },

        // ── Authenticated user ──────────────────────────────────────────────
        {
            name: 'meine-akkreditierungen',
            path: '/meine-akkreditierungen',
            states: ['filled', 'empty'],
            auth: 'user',
            tenant: { empty: 'primary' },
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Meine Akkreditierungen' }],
            note: 'The page shows only the logged-in user\'s own applications — "empty" is the dataset user that applies for nothing (no tenant with data is involved). The filled user has exactly one requested application; the applications themselves are recreated per run (they cascade away with their category in the reset), the user row is reused.',
            seeds: {
                filled: () => seedMyAccreditationsFilled(),
                empty: () => seedUserWithoutApplication(),
            },
        },
        {
            name: 'apply',
            path: '/apply/:accreditationId',
            states: ['filled'],
            auth: 'user',
            nav: [
                { kind: 'click', scope: 'banner', role: 'link', name: 'Akkreditierungen' },
                {
                    kind: 'click',
                    scope: 'main',
                    role: 'link',
                    name: 'Beantragen',
                    within: 'categoryName',
                    withinRole: 'heading',
                },
            ],
            seeds: { filled: () => seedApplyFilled() },
            note: 'The public list holds BOTH of the dataset\'s categories, so the "Beantragen" click is scoped to the card whose HEADING is exactly the seed\'s category name — never by a name substring, which used to match the print category as well and made the desktop capture show a different accreditation than the mobile one. The spec additionally asserts the landed URL against the resolved route path, so a wrong click fails the run instead of producing a plausible screenshot.',
        },

        // ── Admin ───────────────────────────────────────────────────────────
        {
            name: 'admin-mandants',
            path: '/admin/mandants',
            states: ['filled'],
            auth: 'admin',
            // The list renders a logo cell per mandant; the primary mandant's
            // cell shows the static fallback, which is only true while it has no
            // uploaded logo — the dataset reset removes one (the E2E logo-upload
            // test can die between upload and removal).
            seeds: { filled: seedPrimaryMandant },
            note: 'Global super-admin surface: the list shows EVERY mandant regardless of the current tenant, so a data-less fixture cannot render it empty — a populated "empty" capture would be expected behavior. Filled only.',
        },
        {
            name: 'admin-mandants-new',
            path: '/admin/mandants/new',
            states: ['filled'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'main', role: 'link', name: 'Neu' }],
            note: 'Pure form, no data dependency — the empty state would be identical to the filled one.',
        },
        {
            name: 'admin-mandant-detail',
            path: '/admin/mandants/:id',
            states: ['filled'],
            auth: 'admin',
            nav: [
                {
                    kind: 'goto',
                    path: '/admin/mandants/:id',
                    reason: 'The detail page id is dynamic and seeded at runtime — deep-link semantics (a user arrives here from the list row link).',
                },
            ],
            seeds: { filled: seedPrimaryMandant },
            note: 'The detail page deep-links into the mandant by runtime id, so its data is the seed. Its HEIGHT, however, is the largest band driver in the run and that deserves a precise note: the page lists the mandant\'s domains (3, fixed) AND its teams, and the team list is not the review\'s to own — MEASURED 50 teams → 7968 px desktop / 9672 px mobile → 23 of the run\'s 48 bands. So this route\'s band count tracks the shared dev database rather than the fixture; three consecutive runs agreed because nothing created teams in between.',
        },
        {
            name: 'admin-categories',
            path: '/admin/categories',
            states: ['filled', 'empty'],
            auth: 'admin',
            tenant: { empty: 'primary' },
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Kategorien' }],
            seeds: { filled: seedAccreditation },
            emptyMock: ['**/api/admin/categories*'],
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the categories list to [] via emptyMock and runs on the primary tenant. The filled list holds exactly the dataset\'s two categories (public accreditation + print accreditation) — the reset removes every E2E-marker category, so the row count is fixed.',
        },
        {
            name: 'admin-events',
            path: '/admin/events',
            states: ['filled', 'empty'],
            auth: 'admin',
            tenant: { empty: 'primary' },
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Events' }],
            seeds: { filled: seedPortalEvent },
            emptyMock: ['**/api/admin/events*'],
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the events list to [] via emptyMock and runs on the primary tenant. The filled list holds the dataset\'s three events (portal, public accreditation, print accreditation) after the reset.',
        },
        {
            name: 'admin-accreditations',
            path: '/admin/accreditations',
            states: ['filled', 'empty'],
            auth: 'admin',
            tenant: { empty: 'primary' },
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Akkreditierungen' }],
            seeds: { filled: seedAccreditation },
            emptyMock: ['**/api/admin/accreditations*'],
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the accreditations list to [] via emptyMock and runs on the primary tenant. The filled list holds the dataset\'s two accreditations.',
        },
        {
            name: 'admin-freigaben',
            path: '/admin/freigaben',
            states: ['filled', 'empty'],
            auth: 'admin',
            tenant: { empty: 'primary' },
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Freigaben' }],
            seeds: { filled: () => seedFreigabenFilled() },
            emptyMock: ['**/api/admin/applications*', '**/api/admin/accreditations*', '**/api/admin/badge-templates*'],
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the applications list (plus the filter/export sources) to [] via emptyMock and runs on the primary tenant. The three requested applications belong to the public accreditation; the print accreditation\'s two applications are APPROVED (that is what the print check exports), so they do not appear as pending work here.',
        },
        {
            name: 'admin-users',
            path: '/admin/users',
            states: ['filled', 'empty'],
            auth: 'admin',
            tenant: { empty: 'primary' },
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Benutzer' }],
            seeds: { filled: () => seedUsersFilled() },
            emptyMock: ['**/api/admin/users*'],
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the users list to [] via emptyMock and runs on the primary tenant. The filled list renders every user the dev DB holds — that includes the E2E suite\'s leftovers, because there is NO delete route for a user and the reset cannot reclaim them. MEASURED 574 rows on 2026-09-28, up from 216 when the harness was written, which is also why this route now needs 2 bands per viewport instead of 0. What the harness guarantees is the part it can: the review\'s own seven users are created once and reused, so this list stops GROWING per ui-review run; the growth comes from functional-suite runs, and closing it needs a backend delete route, which is a separate board position and deliberately not worked around here.',
        },
        {
            name: 'admin-badge-templates',
            path: '/admin/badge-templates',
            states: ['filled', 'empty'],
            auth: 'admin',
            tenant: { empty: 'primary' },
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Ausweis-Templates' }],
            seeds: { filled: seedBadgeTemplate },
            emptyMock: ['**/api/admin/badge-templates*'],
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the badge-templates list to [] via emptyMock and runs on the primary tenant. The filled list holds exactly two rows: the legacy three-field template and the schema-v2 default (the reset removes every E2E-marker template, which is also what fixed the 14 accumulated ones).',
        },
        {
            name: 'admin-badge-editor-new',
            path: '/admin/badge-templates',
            states: ['filled'],
            auth: 'admin',
            viewports: ['desktop'],
            nav: [
                { kind: 'click', scope: 'complementary', role: 'link', name: 'Ausweis-Templates' },
                { kind: 'click', scope: 'main', role: 'button', name: 'Neu' },
            ],
            seeds: { filled: seedBadgeTemplateSchemaV2 },
            note: 'Badge template EDITOR (FE2) opened via the "Neu" button — an empty editor over the seeded list. Desktop-only because the mobile harness bypass (navbar overflow H5) skips ALL nav steps, which would capture the plain list instead of the open modal; the list itself is covered by admin-badge-templates in both viewports.',
        },
        {
            name: 'admin-badge-editor-edit',
            path: '/admin/badge-templates',
            states: ['filled'],
            auth: 'admin',
            viewports: ['desktop'],
            nav: [
                { kind: 'click', scope: 'complementary', role: 'link', name: 'Ausweis-Templates' },
                {
                    kind: 'click',
                    scope: 'main',
                    role: 'button',
                    name: 'Bearbeiten',
                    within: 'templateName',
                    withinRole: 'cell',
                },
            ],
            seeds: { filled: seedBadgeTemplateSchemaV2 },
            note: 'Badge template EDITOR (FE2) with the SEEDED complete schema-v2 template loaded — all nine canvas boxes (photo top-left, qr top-right, seven data fields with size/align) + properties panel populated, non-overlapping inside the A6 bounds. This is the EDITOR half of the print pair: `admin-badge-print-pdf-*.png` is the same template as the printer receives it, and the two belong in ONE vision batch. The "Bearbeiten" click is scoped to the template\'s row by name, because the list is sorted newest first and the bare first button used to open whichever template was created last.',
        },
        {
            name: 'admin-badge-editor-selected',
            path: '/admin/badge-templates',
            states: ['filled'],
            auth: 'admin',
            viewports: ['desktop'],
            nav: [
                { kind: 'click', scope: 'complementary', role: 'link', name: 'Ausweis-Templates' },
                {
                    kind: 'click',
                    scope: 'main',
                    role: 'button',
                    name: 'Bearbeiten',
                    within: 'templateName',
                    withinRole: 'cell',
                },
                { kind: 'click', scope: 'main', role: 'button', name: 'Feld Name' },
            ],
            seeds: { filled: seedBadgeTemplateSchemaV2 },
            note: 'Badge template EDITOR („Raster + konfigurierbare Labels"): one canvas field SELECTED on the 5 mm raster, properties panel populated with the mm X/Y/W/H inputs, the raster hint below the preview. Desktop-only like the sibling editor routes; the row-scoped "Bearbeiten" click keeps the opened layout the schema-v2 one in every run.',
        },
        {
            name: 'admin-media',
            path: '/admin/media',
            states: ['filled'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Logo & Header' }],
            // The page reads the current mandant's portal overview
            // (`/api/portal/overview`), so the logo state IS its data. The dataset
            // reset guarantees the documented baseline (the E2E logo-upload test
            // mutates exactly this row). The header field needs no seed: no E2E
            // test uploads one.
            seeds: { filled: seedPrimaryMandant },
            note: 'Self-service media page reads the current mandant\'s portal overview (`/api/portal/overview`) — no other data seed needed. The dataset reset guarantees the "no uploaded logo" baseline (the E2E suite\'s logo-upload test mutates exactly this row, and this suite has no teardown of its own), so a separate "empty" state would render identically to "filled"; captured once. The header is null because no E2E test ever uploads one.',
        },
    ],
};

export const routes = uiReviewConfig.routes;
