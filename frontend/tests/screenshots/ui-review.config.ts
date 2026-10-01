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

/**
 * The accessible roles a content postcondition may name.
 *
 * Declared here rather than imported because Playwright 1.63 INLINES the role
 * union into `getByRole`'s signature and exports no `AriaRole` name to alias
 * (checked: `import type { AriaRole } from '@playwright/test'` has no exported
 * member). An import would not compile; `role: string` WOULD compile and would
 * hand every role name to the runtime unchecked.
 *
 * Being a narrower union is the point: a role this manifest has never used has
 * to be added here deliberately, with a reason, instead of being a typo that
 * fails as "0 elements matched" at capture time.
 */
export type UiReviewRole =
    | 'article'
    | 'cell'
    | 'columnheader'
    | 'dialog'
    | 'heading'
    | 'link'
    | 'listitem'
    | 'region'
    | 'row';

/**
 * ## WHAT the capture must show, as a landmark-scoped locator
 *
 * The harness knew the state it was capturing (`filled` or `empty`) and never
 * used it: `settleAndCapture` waited for `networkidle` plus a flat 300 ms. After
 * a CLIENT-SIDE navigation `networkidle` is satisfied before the click's fetch is
 * even issued, so that 300 ms was the entire reserve — and the data arrives after
 * 102–163 ms on this stack, leaving 159–220 ms of headroom. With 400 ms of delay
 * on `/api/admin/users`: `rows=0`, a 950 px page, **zero** bands (a table that has
 * not rendered fits the fold), and every assertion PASSED — the run stored the
 * loading spinner and called it a capture.
 *
 * The asymmetry made it worse: desktop navigates by click and mobile loads the
 * document (`ui-screenshots.spec.ts`'s mobile bypass), so the SAME delay was
 * absorbed on one viewport and not the other. Desktop was the only viewport
 * without a real wait.
 *
 * So every state declares the content the capture is ABOUT, and the spec waits
 * for THAT. It is a postcondition, not a longer sleep: `toBeVisible` and
 * `expect.poll` retry, and a loading spinner satisfies neither — the empty-state
 * headings and list rows are all rendered behind `!isLoading`, which is what makes
 * "empty" and "still loading" mutually exclusive by construction rather than by
 * hope.
 *
 * Two marker shapes, because the pages are not uniform:
 * - `role` (+ optional `name`, `min`) — a heading, a dialog, a table `cell`.
 *   Table data cells are `cell` while header cells are `columnheader`, so a
 *   `cell` count is a header-proof "there is a row" and NOT a "there is a table":
 *   six admin pages render an empty `<table>` beside their empty-state card, so a
 *   "table visible" check would pass on an empty list.
 * - `text` — for the pages whose settled marker is plain text with no role
 *   (`/admin/freigaben`'s tab bodies).
 *
 * `note` is REQUIRED: it is the record of why this marker and not the obvious
 * neighbour, which is the only thing that keeps the next person from swapping in
 * something that happens to pass.
 */
export interface UiReviewContent {
    /** Landmark the postcondition is scoped to. Every route here is page content. */
    scope: 'main';
    /** A container the content must be INSIDE — e.g. the portal calendar region. */
    within?: { role: UiReviewRole; name?: string };
    /** Accessible role; the match count must reach `min`. */
    role?: UiReviewRole;
    /** Exact accessible name for the role locator. */
    name?: string;
    /**
     * Seed key whose value IS the exact accessible name — required wherever the
     * name is a fixture value (an event title, the mandant's name) rather than a
     * UI constant, for the same reason the nav steps have it: a hard-coded copy
     * of a fixture name is a second source of truth that rots silently, and an
     * id cannot be matched by an accessible name at all.
     */
    nameFrom?: string;
    /** Minimum number of matches. Default 1. */
    min?: number;
    /** Plain-text marker for pages whose settled marker carries no role. Exact. */
    text?: string;
    /**
     * Turns the `text` marker into a COUNT marker: the pattern's FIRST capture
     * group must wrap the number the page displays, and that number — read out of
     * the matched element, not out of the manifest — is what the sidecar's
     * `contentCount` records (see `helpers/content-count.ts`).
     *
     * Required wherever the marker's whole point is a QUANTITY, which is
     * `/konto`: its two states are distinguished by `1 Antrag` vs `0 Anträge` and
     * by nothing else a reviewer can see. Without this the harness can assert
     * presence only, and the sidecar has to say "there is one of these" — which on
     * the empty state is a number the page does not show (MEASURED 2026-10-01:
     * `contentCount: 1` on all four `/konto` empty captures while the page read 0).
     *
     * Deliberately WITHOUT the `g`/`y` flag: the pattern object is shared across
     * captures and `exec` on a global/sticky regexp resumes at `lastIndex`.
     */
    textCount?: RegExp;
    /** Why THIS marker. Required — the declaration is the contract. */
    note: string;
}

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
    /**
     * The content postcondition per state — REQUIRED for every state the route
     * captures. Missing is a hard error in the spec, never a skipped wait: a route
     * without one is exactly the "desktop capture photographed the spinner"
     * failure this declaration exists to make impossible.
     */
    content: Partial<Record<UiReviewState, UiReviewContent>>;
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
            content: {
                // Both markers sit INSIDE the calendar region, and that is the load-bearing part: the whole
                // portal body is behind `mandant && !overviewLoading && !overviewError`
                // (PortalHomePage.tsx:80), so a region that exists at all already means the overview
                // resolved. Without the region scope, "a heading named Keine Veranstaltungen exists" would
                // also be true for a page that never finished loading.
                filled: {
                    scope: 'main',
                    within: { role: 'region', name: 'Veranstaltungskalender' },
                    role: 'link',
                    min: 1,
                    note: 'one calendar card. The cards are `<Link className="card">`, so role link — and the ' +
                        'count is the number a reviewer wants, not merely "> 0".',
                },
                empty: {
                    scope: 'main',
                    within: { role: 'region', name: 'Veranstaltungskalender' },
                    role: 'heading',
                    name: 'Keine Veranstaltungen',
                    note: 'the empty-state card title (PortalHomePage.tsx:171, NO trailing period). It is ' +
                        'rendered only behind `events && events.length === 0`, i.e. only after the list ' +
                        'request resolved — so it cannot be mistaken for loading.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'article',
                    min: 1,
                    note: 'one public accreditation card (AccreditationsPage.tsx). Cards and the empty ' +
                        'state are length-gated, so an article can only appear after the list resolved.',
                },
                empty: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Keine Akkreditierungen verfügbar.',
                    note: 'the empty-state card title (AccreditationsPage.tsx), behind the same ' +
                        'length-gated branch as the cards.',
                },
            },
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
            content: {
                // A pure form, so there is no data to wait for. What there IS to wait for is the route
                // GUARD: RequireAuth/RequireAdmin render a spinner and nothing else until the session is
                // known, so the page's h1 is the first thing that proves the guard resolved.
                filled: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Anmelden',
                    note: 'the page h1 (LoginPage.tsx). Role heading, not the submit button: both are ' +
                        'named "Anmelden", and the button exists from the first paint of the form.',
                },
                empty: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Anmelden',
                    note: 'same marker as filled, deliberately: this route has no data dependency, so the two ' +
                        'captures are expected to be identical and the postcondition says exactly that.',
                },
            },
            states: ['filled', 'empty'],
            auth: 'guest',
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Anmelden' }],
            note: 'Pure form, no data dependency — filled and empty render identically; captured on both mandants for completeness.',
        },
        {
            name: 'verify',
            path: '/verify',
            content: {
                filled: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Verifizieren',
                    note: 'the page h1 (VerifyPage.tsx) — the form is rendered only after the route guard ' +
                        'resolved. Same shape as the login route: no list, so the guard is the settle.',
                },
                empty: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Verifizieren',
                    note: 'same marker as filled, deliberately — the empty form is the filled form.',
                },
            },
            states: ['filled', 'empty'],
            auth: 'guest',
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Verifizieren' }],
        },
        {
            name: 'verify-token',
            path: '/verify/:token',
            content: {
                filled: {
                    scope: 'main',
                    role: 'article',
                    min: 1,
                    note: 'the RESULT card (VerifyPage.tsx), which the page renders only after the ' +
                        'verification POST resolved; the spinner is what it replaces. This is the ' +
                        'one route where the capture is about a RESPONSE rather than about a form, and ' +
                        'without this marker a delayed verification would photograph the spinner.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'heading',
                    nameFrom: 'eventName',
                    note: "the event h1 (EventDetailPage.tsx:50), which IS the event's own title — and the " +
                        'page early-returns a spinner (`:39`) until the event has loaded, so the h1 is the ' +
                        "settle. The name comes from the seed because the title is a fixture value: an id " +
                        'cannot be matched by an accessible name, and a copied literal would be a second ' +
                        'source of truth that rots when the fixture is renamed.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'article',
                    min: 1,
                    note: 'one application card (MyAccreditationsPage.tsx). The dataset user has exactly ' +
                        "one requested application, so the count a reviewer sees is 1 — and the sidecar " +
                        'records what was actually rendered, not what was hoped for.',
                },
                empty: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Noch keine Anträge',
                    note: 'the empty-state card title (MyAccreditationsPage.tsx, NO trailing period — the ' +
                        'exact literal is load-bearing, a "friendly" added period would simply never match). ' +
                        'It is length-gated, so it appears only after the list resolved.',
                },
            },
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
            name: 'konto',
            path: '/konto',
            content: {
                // The COUNT, not the page's headings — and that is the whole
                // point of this entry. The two states render an IDENTICAL card
                // structure; the only difference a reviewer can see is the number
                // in "Meine Anträge" (1 Antrag vs 0 Anträge). A marker on the h1 or
                // on a card title would therefore pass in BOTH states while the
                // capture showed the wrong one, which is the same "spinner
                // photographed as content" failure one level up. Naming the count
                // makes the postcondition say what the capture is FOR: this is the
                // route that shows the numbers a deletion would take.
                filled: {
                    scope: 'main',
                    text: '1 Antrag',
                    // The quantity, so the sidecar records the number the page
                    // SHOWS rather than "a marker was found" (helpers/content-count.ts).
                    textCount: /(\d+)/,
                    note: 'the applications count in the "Meine Anträge" card (AccountPage.tsx:127), ' +
                        'rendered from the `applicant` dataset user — who has exactly ONE requested ' +
                        'application. Not the h1 "Mein Konto" (`:89`), which sits OUTSIDE the ' +
                        '`account && !isLoading && !error` guard and is therefore on screen while the ' +
                        'fetch is still in flight, and not the card title, which is present in both ' +
                        'states. A `text` marker and not a `cell`/role one: the value is a `<dd>` in a ' +
                        'plain `<dl>`, which carries no ARIA role, so there is no semantic handle for it ' +
                        '— the same situation `admin-freigaben` documents for its tab bodies. The German ' +
                        'singular ("1 Antrag") is the load-bearing half: the sibling states of this page ' +
                        'differ in exactly that word, and a substring match would be satisfied by both. ' +
                        '`textCount` is what makes the SIDEcar say 1 here and 0 in `empty` — without it ' +
                        'both states recorded a hardcoded 1, i.e. the empty state carried a number the page ' +
                        'contradicts (MEASURED 2026-10-01).',
                },
                empty: {
                    scope: 'main',
                    text: '0 Anträge',
                    // Same rule as `filled` — and this is where the old hardcoded
                    // `1` was visible: the marker matched, so the capture passed,
                    // while the sidecar claimed a quantity the page contradicts.
                    textCount: /(\d+)/,
                    note: 'the same count for the dataset user that applies for NOTHING ' +
                        '(`seedUserWithoutApplication`), i.e. the state a freshly registered user is in. ' +
                        'Zero is the design-QA case worth capturing: it is what exercises the plural ' +
                        'branch of the label and shows whether an empty account reads as "nothing to ' +
                        'delete" or as a broken count. Distinct from `filled` by the `0`/`1`, not by the ' +
                        'wording around it.',
                },
            },
            states: ['filled', 'empty'],
            auth: 'user',
            tenant: { empty: 'primary' },
            nav: [{ kind: 'click', scope: 'banner', role: 'link', name: 'Mein Konto' }],
            seeds: {
                filled: () => seedMyAccreditationsFilled(),
                empty: () => seedUserWithoutApplication(),
            },
            note: 'Self-service account area (identity, the counts a deletion would take, the deletion ' +
                'itself — AccountPage.tsx, added in f0f86ce). It was outside the design-QA loop until this ' +
                'entry, i.e. §7 step 4 could not review a change to it at all. NO `emptyMock`: the ' +
                'empty state is a different USER (`empty.localhost` is unreachable in local dev — F6, see ' +
                'the module header — and the page is mandant-agnostic anyway), so both states run on the ' +
                'primary tenant and differ only in the seeded user, exactly like `meine-akkreditierungen`. ' +
                'KNOWN GAP, named rather than papered over: the CONFIRM DIALOG (`AccountDeleteDialog`, ' +
                'reached by clicking "Konto löschen") is NOT captured. It is the one irreversible action ' +
                'on the page and the most layout-sensitive surface it has, and adding it is a separate ' +
                'route entry (desktop-only, as the other dialog routes are) rather than a state of this ' +
                'one — the manifest\'s `states` mean data states of ONE page, and a modal is a different ' +
                'page. Deleting the account is deliberately NOT what this route asserts: a capture that ' +
                'clicked through would destroy the fixture every other route logs in with.',
        },
        {
            name: 'apply',
            path: '/apply/:accreditationId',
            content: {
                filled: {
                    scope: 'main',
                    role: 'article',
                    min: 1,
                    note: 'the form card (ApplyPage.tsx:73). The page renders the spinner at `:51` until the ' +
                        'accreditation loaded, so the card is the settle — and it is the card rather than a ' +
                        'heading, because the h1 at `:49` sits OUTSIDE the loading guard and is therefore ' +
                        'already on screen while the data is still in flight.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'cell',
                    min: 1,
                    note: 'table DATA cells, not the table: header cells carry role columnheader, so a cell ' +
                        'count is a header-proof "there is a row". This page renders an empty <table> BESIDE ' +
                        'its empty state (the table is guarded on !isLoading, not on length), so a ' +
                        '"table is visible" check would pass on a list with nothing in it.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Neuer Mandant',
                    note: 'the page h1 (MandantFormPage.tsx:32). The page fetches nothing, so the settle is ' +
                        'the admin route guard — and the h1 is the first thing it renders.',
                },
            },
            states: ['filled'],
            auth: 'admin',
            nav: [{ kind: 'click', scope: 'main', role: 'link', name: 'Neu' }],
            note: 'Pure form, no data dependency — the empty state would be identical to the filled one.',
        },
        {
            name: 'admin-mandant-detail',
            path: '/admin/mandants/:id',
            content: {
                filled: {
                    scope: 'main',
                    role: 'heading',
                    nameFrom: 'mandantName',
                    note: 'the mandant h1 (MandantDetailPage.tsx:198). The page early-returns a spinner ' +
                        'until the mandant loaded, so this is the settle for the WHOLE page — ' +
                        'including the two list sections below it, whose <ul> renders (empty, and therefore ' +
                        'immediately) even while the mandant is still being fetched.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'cell',
                    min: 1,
                    note: 'table data cells (cell, not columnheader) — see admin-mandants. The empty state ' +
                        'here is a card with an h2 (CategoriesPage.tsx:234), so the two are distinguishable.',
                },
                empty: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Noch keine Kategorien vorhanden.',
                    note: 'the empty-state card title (CategoriesPage.tsx:234). It renders behind !isLoading, ' +
                        'so it is structurally impossible to see it while the spinner is up — which is what ' +
                        'makes "empty" and "still loading" two different captures rather than one.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'cell',
                    min: 1,
                    note: 'table data cells — see admin-categories.',
                },
                empty: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Noch keine Events vorhanden.',
                    note: 'the empty-state card title (EventsPage.tsx:300), behind !isLoading.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'cell',
                    min: 1,
                    note: 'table data cells — see admin-categories. This page also has a sub-accreditation ' +
                        'modal with its own article cards; scoping to main and counting cells keeps the modal ' +
                        'out of it.',
                },
                empty: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Noch keine Akkreditierungen vorhanden.',
                    note: 'the empty-state card title (AccreditationsPage.tsx:429), behind !isLoading. The ' +
                        'sub-modal carries near-identical text (`Noch keine Sub-Akkreditierungen vorhanden.`) ' +
                        '— a different string, and therefore excluded by the exact name.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'cell',
                    min: 1,
                    note: 'table data cells of the default tab (Anträge). The three tab bodies are separate ' +
                        'components, and this is the one the route opens on (ApprovalsPage.tsx:1240-1260).',
                },
                empty: {
                    scope: 'main',
                    text: 'Keine Anträge vorhanden.',
                    note: 'A TEXT marker and not a role one, which is a property of the page rather than a ' +
                        'shortcut: this empty state is a bare expression in JSX (ApprovalsPage.tsx:679) and ' +
                        'carries no role, so there is no semantic handle for it. Exact match, and the ' +
                        'near-identical tab siblings (`Keine Sub-Anträge vorhanden.`, ' +
                        '`Keine Blacklist-Einträge vorhanden.`) are different strings and cannot satisfy it.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'cell',
                    min: 1,
                    note: 'table data cells. This list is PAGINATED (UsersPage.tsx slices at PAGE_SIZE), ' +
                        'so the count that reaches the sidecar is the rendered PAGE, not the mandant total; ' +
                        "the total travels separately as the seed's numeric userCount.",
                },
                empty: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Noch keine Benutzer vorhanden.',
                    note: 'the empty-state card title (UsersPage.tsx:229). The page has a second empty text for ' +
                        'an active search (`Keine Benutzer für die Suche.`); a capture never types ' +
                        'into the search box, so the unfiltered one is the honest marker and the exact name ' +
                        'excludes its sibling.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'cell',
                    min: 1,
                    note: 'table data cells — see admin-categories.',
                },
                empty: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Noch keine Ausweis-Templates',
                    note: 'the empty-state card title (BadgeTemplatesPage.tsx:322, NO trailing period).',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'dialog',
                    min: 1,
                    note: 'the open editor. `<dialog>` (Modal.tsx:127) has no implicit role while closed and is ' +
                        'display:none, so role dialog resolves only once the modal is really open — which is ' +
                        'the whole subject of this route. The list behind it also has cells, so counting ' +
                        'dialogs is what separates "editor open" from "list rendered".',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'dialog',
                    min: 1,
                    note: 'the open editor with the seeded template loaded — see admin-badge-editor-new. This ' +
                        "route additionally needs the LIST to be settled before its row-scoped \"Bearbeiten\" " +
                        'click can resolve, and the nav step’s own click already waits for that name.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'dialog',
                    min: 1,
                    note: 'the open editor with one canvas field selected — see admin-badge-editor-new. The ' +
                        'last nav step clicks a field by name and Playwright waits for that name, so this ' +
                        'marker is what closes the step after it.',
                },
            },
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
            content: {
                filled: {
                    scope: 'main',
                    role: 'heading',
                    name: 'Logo & Header',
                    note: 'the page h1 (MandantMediaPage.tsx:38). The page early-returns a spinner ' +
                        'while the mandant overview is in flight, so the h1 is the settle for the logo and ' +
                        'header state the capture is about. This page has no list and therefore no empty ' +
                        'state — it captures only `filled`, and the manifest says why.',
                },
            },
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
