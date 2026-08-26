import {
    registerAndActivateUser,
} from '../e2e/helpers/admin-data';
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
    seedUsersFilled,
} from './helpers/seeds';

/**
 * UI-review route manifest — the single source of truth for which pages get
 * screenshotted and in which states. Edit this file to add/remove routes; the
 * generic spec (`ui-screenshots.spec.ts`) picks the changes up automatically.
 *
 * State semantics:
 * - `filled` — deterministic seeded data via the route's `seeds.filled`
 *   (reusing the E2E admin-data helpers on the primary mandant).
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
 * `{data: []}`, or a custom body for non-list endpoints such as
 * `/api/portal/overview`) and run on the primary tenant; the real fix is
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
    /** Restrict to an <article> whose text contains the seed value for this key first. */
    within?: string;
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
    /** Must mirror `outputDir` in playwright.screenshots.config.ts — the spec builds absolute screenshot paths from it. */
    outputDir: string;
    routes: UiReviewRoute[];
}

export const uiReviewConfig: UiReviewConfig = {
    outputDir: 'test-results/ui-screenshots',
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
            note: 'Public guest page. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the portal overview (an empty mandant without logo/header/teams) and the events list ([]) via emptyMock and runs on the primary tenant — the homepage renders a genuine empty portal ("Keine Veranstaltungen") instead of a load error or the filled primary data.',
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
                    reason: 'Approved-application QR links have no UI entry point — the token comes from the seeded approved application (P4 badge flow), a justified direct-URL load.',
                },
            ],
            seeds: { filled: seedApprovedApplicationCached },
        },
        {
            name: 'events-detail',
            path: '/events/:id',
            states: ['filled'],
            auth: 'guest',
            nav: [{ kind: 'click', scope: 'main', role: 'link' }],
            seeds: { filled: seedPortalEvent },
        },

        // ── Authenticated user ──────────────────────────────────────────────
        {
            name: 'meine-akkreditierungen',
            path: '/meine-akkreditierungen',
            states: ['filled', 'empty'],
            auth: 'user',
            tenant: { empty: 'primary' },
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Meine Akkreditierungen' }],
            note: 'The page shows only the logged-in user’s own applications — "empty" is a freshly activated user without applications on the primary mandant (no tenant with data is involved).',
            seeds: {
                filled: () => seedMyAccreditationsFilled(),
                empty: () => registerAndActivateUser(),
            },
        },
        {
            name: 'apply',
            path: '/apply/:accreditationId',
            states: ['filled'],
            auth: 'user',
            nav: [
                { kind: 'click', scope: 'banner', role: 'link', name: 'Akkreditierungen' },
                { kind: 'click', scope: 'main', role: 'link', name: 'Beantragen', within: 'categoryName' },
            ],
            seeds: { filled: () => seedApplyFilled() },
        },

        // ── Admin ───────────────────────────────────────────────────────────
        {
            name: 'admin-mandants',
            path: '/admin/mandants',
            states: ['filled'],
            auth: 'admin',
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
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the categories list to [] via emptyMock and runs on the primary tenant.',
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
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the events list to [] via emptyMock and runs on the primary tenant.',
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
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the accreditations list to [] via emptyMock and runs on the primary tenant.',
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
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the applications list (plus the filter/export sources) to [] via emptyMock and runs on the primary tenant.',
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
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the users list to [] via emptyMock and runs on the primary tenant.',
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
            note: 'Mandant-scoped list. The empty.localhost fixture is unreachable in local dev (F6, see module header), so the empty state stubs the badge-templates list to [] via emptyMock and runs on the primary tenant.',
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
            note: 'Badge template EDITOR (FE2) opened via the "Neu" button — an empty editor over the seeded list. Desktop-only because the mobile harness bypass (navbar overflow H5) skips ALL nav steps, which would capture the plain list instead of the open modal; the list itself is covered by admin-badge-templates in both viewports. Seeded with the full schema-v2 template (not the legacy three-field one) so the concurrent editor captures stay deterministic — "Bearbeiten" opens the NEWEST row, and with both editor routes seeding the same complete template that row is always a schema-v2 layout.',
        },
        {
            name: 'admin-badge-editor-edit',
            path: '/admin/badge-templates',
            states: ['filled'],
            auth: 'admin',
            viewports: ['desktop'],
            nav: [
                { kind: 'click', scope: 'complementary', role: 'link', name: 'Ausweis-Templates' },
                { kind: 'click', scope: 'main', role: 'button', name: 'Bearbeiten' },
            ],
            seeds: { filled: seedBadgeTemplateSchemaV2 },
            note: 'Badge template EDITOR (FE2) with a SEEDED full schema-v2 template loaded — all nine canvas boxes (photo top-left, qr top-right, seven data fields with size/align) + properties panel populated, non-overlapping inside the A6 bounds. Same desktop-only reasoning as admin-badge-editor-new; same shared seed so the newest-row click always lands on a complete layout.',
        },
        {
            name: 'admin-media',
            path: '/admin/media',
            states: ['filled'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'complementary', role: 'link', name: 'Logo & Header' }],
            note: 'Self-service media page reads the current mandant\'s portal overview (`/api/portal/overview`) — no seed needed. The primary mandant has no uploaded logo/header yet, so a separate "empty" state would render identically to "filled"; captured once.',
        },
    ],
};

export const routes = uiReviewConfig.routes;
