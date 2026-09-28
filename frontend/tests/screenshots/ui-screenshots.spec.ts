import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import path from 'node:path';
import process from 'node:process';
import { routes, uiReviewConfig } from './ui-review.config';
import type { UiReviewClickStep, UiReviewNavStep, UiReviewRoute, UiReviewState, UiReviewViewport } from './ui-review.config';
import { EMPTY_MANDANT_ORIGIN, ensureEmptyMandant } from './helpers/empty-mandant';
import { storeRouteCapture } from './helpers/capture-store';
import { uiReviewDataset } from './helpers/dataset';
import { loginViaUi, waitForAppSettled } from './helpers/session';

/**
 * Generic manifest-driven screenshot spec for the ui-review skill.
 *
 * Iterates the route × state × viewport matrix from `ui-review.config.ts` and
 * captures a full-page PNG per combination, plus one section capture per
 * viewport-height step down the page (`<name>-secN.png`, see `captureSections`)
 * so that the review can read what is below the fold. All tests are tagged
 * `@screenshot` so the set is groupable and clearly separate from the functional
 * E2E tags.
 *
 * ## Captures overwrite, they never delete
 *
 * Every capture is written through `helpers/capture-store.ts`, which archives the
 * file it replaces into `<dir>/prev/`. A partial re-capture — the §7 fix loop's
 * `pnpm test:screenshots -g <route>` — therefore leaves BOTH halves of the
 * comparison on disk: untouched routes byte-for-byte, the re-taken route as
 * `prev/<name>.png` next to `<name>.png`. (Before the store existed the harness
 * wrote into Playwright's own `outputDir`, which is deleted at the start of every
 * run: measured 126 PNG → 11 after one partial run, i.e. no "old" at all.)
 *
 * Every capture also records its measurements — above all the SECTION BAND COUNT
 * — in `<name>.meta.json` next to the images, which is what makes a review batch
 * auditable and reproducible (it is a function of the page height, which is a
 * function of the dataset, which `helpers/dataset.ts` fixes per run).
 *
 * STRICT frontend rules obeyed here:
 * - SPA navigation happens via UI clicks (`nav` steps). `page.goto` is only
 *   used where justified and documented:
 *   1. the LOGIN page is loaded by direct URL — the header "Anmelden" link is
 *      off-viewport/unreachable in the mobile navbar (a known overflow the
 *      harness exists to surface), so login is reachable there only as a deep
 *      link (route-guard / direct-URL semantics);
 *   2. per-route `goto` nav steps for dynamic detail pages (reason in the
 *      manifest);
 *   3. on the mobile viewport, routes whose nav starts with a HEADER or a
 *      `complementary` (admin sidebar) click are loaded by direct URL instead
 *      — the same navbar overflow makes header nav clicks unreliable at 360px,
 *      and the admin sidebar sits behind the CLOSED daisyUI drawer (H5): its
 *      `drawer-side` is `visibility: hidden`, so the `complementary`
 *      landmark's links are absent from the a11y tree until the hamburger is
 *      opened. Resolved-URL loads capture the exact page deterministically;
 *      the desktop path still clicks the landmarks' links.
 * - Login flows through the real UI form — no localStorage injection.
 * - Locators are scoped to landmarks (`banner` / `complementary` / `main`), and
 *   list rows are addressed by role (`article` card or table `row`), never by CSS
 *   class.
 */

const PRIMARY_ORIGIN = process.env.E2E_BASE_URL ?? 'http://localhost:5173';
const ADMIN_EMAIL = 'admin@example.com';
const ADMIN_PASSWORD = 'admin';

/**
 * How long a content postcondition may take to become true. Generous on
 * purpose: this is a CEILING, not a budget. The assertion returns the moment the
 * content is there, so a slow page costs only its own latency — while a page
 * that never renders fails after this long instead of hanging until the
 * suite-wide timeout. Measured: the data itself arrives in 102–163 ms.
 */
const contentTimeoutMs = 15000;

function viewportForProject(projectName: string): UiReviewViewport {
    return projectName === 'Mobile Chrome' ? 'mobile' : 'desktop';
}

function tenantOrigin(route: UiReviewRoute, state: UiReviewState): string {
    const tenant = route.tenant?.[state] ?? (state === 'empty' ? 'empty' : 'primary');
    return tenant === 'empty' ? EMPTY_MANDANT_ORIGIN : PRIMARY_ORIGIN;
}

function resolvePath(pattern: string, params: Record<string, unknown>): string {
    return pattern.replace(/:([A-Za-z]+)/g, (_match, key: string) => {
        const value = params[key];
        if (value === undefined || value === null) {
            throw new Error(`Route param "${key}" was not resolved by the seed for "${pattern}"`);
        }
        return String(value);
    });
}

interface SectionCapture {
    /** One viewport-height band per scroll step, in order. */
    bands: Buffer[];
    measurements: { scrollHeightPx: number; viewportHeightPx: number };
}

/**
 * The content postcondition: WHAT the capture must show, in a landmark-scoped
 * locator, waited for with retrying assertions.
 *
 * ## Why this exists, measured
 *
 * The harness knew the state it was capturing (`filled` / `empty`) and used
 * nothing but a clock: `waitForAppSettled` is `networkidle` + a flat 300 ms.
 * After a CLIENT-SIDE navigation `networkidle` is satisfied before the click's
 * fetch is even issued, so that 300 ms was the only reserve — and the data
 * arrives after 102–163 ms on this stack, leaving 159–220 ms of headroom. With
 * 400 ms of delay on `/api/admin/users` and the dataset unchanged: `rows=0`,
 * `h=950`, `bands=0` (a table that has not rendered fits the fold), and every
 * assertion PASSED. The run stored the loading spinner and called it a capture.
 *
 * The asymmetry is structural, not incidental: desktop navigates by CLICK
 * (`applyNavStep`) and mobile loads the document through the mobile bypass, so
 * the same delay was absorbed on one viewport and not the other. Desktop was
 * the only viewport without a real wait.
 *
 * ## Why it is a postcondition and not a longer sleep
 *
 * A longer budget is the same mistake with a bigger number: it converts a
 * correctness property into a timing race, and it is wrong again the moment the
 * machine is loaded. `toBeVisible` and `expect.poll` RETRY, so this absorbs any
 * delay and only fails when the content genuinely does not arrive — and it also
 * makes the suite faster, because a settled page is captured as soon as it is
 * settled.
 *
 * ## The spinner cannot pass for content
 *
 * Two independent reasons, and the second is the one that matters:
 * 1. every marker below is rendered behind the page's own `!isLoading` guard
 *    (empty-state headings, list rows, result cards), so while the spinner is
 *    up the marker is absent;
 * 2. the explicit `toHaveCount(0)` on the spinner afterwards, so a future page
 *    that renders its empty state while loading is caught by a name in the error
 *    message instead of a silent wrong capture.
 */
async function waitForContent(
    page: Page,
    route: UiReviewRoute,
    state: UiReviewState,
    seed: Record<string, unknown>,
): Promise<number> {
    const content = route.content[state];
    if (content === undefined) {
        throw new Error(
            `Route "${route.name}" declares no content postcondition for the "${state}" state. Every state a ` +
                'capture can be taken in must say what the capture is ABOUT — see UiReviewContent in ' +
                'ui-review.config.ts. A missing one is not a skipped wait, it is a capture that can photograph ' +
                'a loading spinner and pass.',
        );
    }

    const scope = page.getByRole(content.scope);
    await expect(scope, `"${route.name}" (${state}) has its main landmark`).toBeVisible();

    let container = scope;
    if (content.within !== undefined) {
        container = scope.getByRole(
            content.within.role,
            content.within.name === undefined ? undefined : { name: content.within.name },
        );
        await expect(
            container,
            `"${route.name}" (${state}) reached its "${content.within.name ?? content.within.role}" container, ` +
                'which is the part of the page the data belongs to',
        ).toBeVisible();
    }

    const min = content.min ?? 1;
    const where = `"${route.name}" (${state}) shows the content it was captured for`;
    if (content.text !== undefined) {
        await expect(container.getByText(content.text, { exact: true }), where).toBeVisible();
        return 1;
    }
    if (content.role === undefined) {
        throw new Error(
            `The content postcondition of "${route.name}" (${state}) declares neither a role nor a text ` +
                'marker, so it cannot be waited for. One of the two is required.',
        );
    }
    // The name may come from the seed — a fixture title is a fixture value, and
    // an id cannot be matched by an accessible name at all.
    let name = content.name;
    if (name === undefined && content.nameFrom !== undefined) {
        const value = seed[content.nameFrom];
        if (value === undefined || value === null) {
            throw new Error(
                `The content postcondition of "${route.name}" (${state}) names its marker from the ` +
                    `seed key "${content.nameFrom}", which the seed did not resolve`,
            );
        }
        name = String(value);
    }
    const matches =
        name === undefined
            ? container.getByRole(content.role)
            : container.getByRole(content.role, { name, exact: true });

    // `expect.poll` because the marker may need several frames to appear (SWR
    // fetch → render → revalidate) and a one-shot `count()` would be a snapshot
    // of the wrong moment — which is the entire bug this function exists to fix.
    await expect
        .poll(() => matches.count(), { message: where, timeout: contentTimeoutMs })
        .toBeGreaterThanOrEqual(min);

    // The explicit spinner exclusion, so the failure names a CAUSE instead of
    // leaving a reviewer to wonder why the page is half-rendered.
    await expect(
        page.locator('.loading-spinner'),
        `"${route.name}" (${state}) is no longer loading. daisyUI's loading indicator ships no role and no ` +
            'aria-label, so its class is the only handle there is — and it is an assertion, not an address, ' +
            'so the "locate by role, never by CSS class" rule is untouched.',
    ).toHaveCount(0);

    return matches.count();
}

/**
 * Section captures — the half of the review that the full-page PNG cannot do.
 *
 * AGENTS.md §7 promises a full-page PNG **plus** `<name>-secN.png` per route ×
 * state × viewport, "in 80-%-Scrollschritten, damit unterhalb des Folds nichts
 * unlesbar skaliert". Measured on the first loop run: 60 full-page PNGs and
 * **0** section captures. The consequence was not cosmetic — nothing below the
 * fold was ever looked at, so the verdict covered the visible area only.
 *
 * A full-page PNG is a single image: a page several viewports tall is scaled
 * down to fit, and the smaller it gets the less a reviewer can read. The bands
 * below restore the native 1:1 pixel size, one viewport at a time, which is what
 * makes "check the field labels below the table" a checkable statement at all.
 *
 * Deliberate properties:
 * - `behavior: 'instant'` — the app sets no `scroll-behavior`, but an implicit
 *   smooth scroll would be screenshotted mid-animation and blur the very text
 *   this function exists to make legible.
 * - The document is scrolled to the bottom and back **before** the height is
 *   measured. Otherwise a band list computed against a still-growing
 *   `scrollHeight` (lazy images, async lists) silently stops short of the end.
 * - The last band is clamped to the bottom, so the final 20 % of the document is
 *   never left to the full-page PNG alone.
 * - A page that fits in one viewport gets **no** band: there is nothing below
 *   the fold, and the full-page PNG already is the visible area. Emitting a
 *   copy of it would pad the review with a duplicate.
 * - The bands are RETURNED, not written here: the store decides what happens to
 *   the file that a previous run left under the same name.
 */
async function captureSections(page: Page): Promise<SectionCapture> {
    // Prime lazy content, then return to the top: see the module-level note.
    await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
    await page.waitForTimeout(200);
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));

    const { scrollHeight, viewportHeight } = await page.evaluate(() => ({
        scrollHeight: document.documentElement.scrollHeight,
        viewportHeight: window.innerHeight,
    }));
    const maxScroll = scrollHeight - viewportHeight;
    if (maxScroll <= 0) {
        return { bands: [], measurements: { scrollHeightPx: scrollHeight, viewportHeightPx: viewportHeight } };
    }

    const step = Math.max(1, Math.round(viewportHeight * uiReviewConfig.sectionScrollStep));
    const offsets: number[] = [];
    for (let offset = 0; offset < maxScroll; offset += step) {
        offsets.push(offset);
    }
    // Clamped to the true bottom — the last step of a 80 % walk never lands on it.
    offsets.push(maxScroll);

    const bands: Buffer[] = [];
    for (const offset of offsets) {
        await page.evaluate((top) => window.scrollTo({ top, behavior: 'instant' }), offset);
        bands.push(await page.screenshot());
    }
    // Leave the page where the full-page capture found it.
    await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    return { bands, measurements: { scrollHeightPx: scrollHeight, viewportHeightPx: viewportHeight } };
}

async function settleAndCapture(
    page: Page,
    route: UiReviewRoute,
    state: UiReviewState,
    viewport: UiReviewViewport,
    dataset: Awaited<ReturnType<typeof uiReviewDataset>>,
    seed: Record<string, unknown>,
): Promise<void> {
    // POSTCONDITION: the capture must show the route the manifest declares, at
    // the id the seed resolved. This is the check that makes the addressing
    // error class LOUD: navigation used to be trusted, so a click that landed on
    // the wrong row produced a perfectly plausible screenshot of the wrong
    // dataset (MEASURED: desktop `apply` showed the print accreditation 201
    // while mobile showed 200, because one took the click path and the other the
    // URL bypass). A wrong landing is now a failed run, and the ids it would have
    // hidden are in the sidecar either way.
    const expectedPathname = resolvePath(route.path, seed);
    expect(
        new URL(page.url()).pathname,
        `"${route.name}" (${state}, ${viewport}) landed on the manifest's route`,
    ).toBe(expectedPathname);

    // The settle that matters. `waitForAppSettled` used to be the whole of it,
    // and it is a `networkidle` plus a flat 300 ms — a CLOCK, not a condition.
    // It stays below as the layout settle for the nav steps, but the capture's
    // correctness now rests on the content the manifest says it is about.
    await waitForAppSettled(page);
    const contentCount = await waitForContent(page, route, state, seed);
    await expect(page.getByRole('main')).toBeVisible();
    // Full page FIRST, on an unscrolled page — so a reviewer comparing against
    // the previous run compares the same thing the previous run compared.
    const fullPage = await page.screenshot({ fullPage: true });
    const sections = await captureSections(page);
    const meta = storeRouteCapture({
        state,
        viewport,
        route: route.name,
        fullPage,
        bands: sections.bands,
        measurements: sections.measurements,
        runKey: dataset.runKey,
        dataset: dataset.fingerprint,
        // The dataset ids the capture was actually rendered against, and the URL
        // it was rendered at. A reviewer (and the §7 acceptance check) can now
        // read the id off the sidecar instead of squinting at the pixels.
        entityIds: entityIdsOf(seed),
        // HOW MUCH of the marker was actually on screen. `entityIds` answers
        // "which row", this answers "how many" — and the two are not
        // interchangeable: `admin-users` paginates, so its seed carries the
        // mandant total (a number, `userCount`) while this is the rendered page.
        // Before this field existed, a credentials-only seed left that route with
        // `entityIds: {}` and NO number anywhere saying against how many users
        // the capture was rendered.
        contentCount,
        pathname: new URL(page.url()).pathname,
    });
    console.log(
        `[ui-review] ${route.name} (${state}, ${viewport}): ${meta.bands} band(s), ` +
            `${meta.scrollHeightPx}px page, ${JSON.stringify(meta.entityIds)} → ` +
            `${path.join(uiReviewConfig.outputDir, state, viewport, meta.file)}`,
    );
}

/**
 * The seed values that identify WHICH row a capture shows, as numbers, so a
 * sidecar reader gets `{"accreditationId": 200}` and not a string.
 *
 * Credentials are deliberately NOT part of this: the sidecar is handed out with
 * the batch (see `dataset.ts`'s `assertNoSecretsInArtifact`), so no address that
 * could log someone in and no token belongs here either.
 */
function entityIdsOf(seed: Record<string, unknown>): Record<string, number> {
    const ids: Record<string, number> = {};
    for (const [key, value] of Object.entries(seed)) {
        if (typeof value === 'number' && Number.isInteger(value)) {
            ids[key] = value;
        }
    }
    return ids;
}

/**
 * The exact accessible name of a click step, from the manifest's `name` or from
 * the seed key `nameFrom` points at. `undefined` means "no name filter" — which
 * the locator below then treats as a strict-mode requirement of exactly ONE
 * matching element, instead of a silent `.first()`.
 */
function resolveName(step: UiReviewClickStep, seed: Record<string, unknown>): { name: string; exact: true } | undefined {
    if (step.nameFrom !== undefined) {
        const value = seed[step.nameFrom];
        if (value === undefined || value === null) {
            throw new Error(`Nav step nameFrom="${step.nameFrom}" was not resolved by the seed`);
        }
        return { name: String(value), exact: true };
    }
    return step.name !== undefined ? { name: step.name, exact: true } : undefined;
}

async function applyNavStep(page: Page, step: UiReviewNavStep, seed: Record<string, unknown>): Promise<void> {
    if (step.kind === 'goto') {
        // Direct-URL load — only for justified routes (deep links / dynamic
        // detail pages); the manifest's `reason` documents each case.
        await page.goto(resolvePath(step.path, seed));
        return;
    }

    const region = page.getByRole(step.scope);
    const name = resolveName(step, seed);
    let locator = region.getByRole(step.role, name);
    if (step.within !== undefined) {
        // Scope to the list container that carries the seed's value: a card
        // (`article`) or a table row (`row`). Role-based, not a CSS selector.
        //
        // The inner locator matches the name EXACTLY (`withinRole`, see the
        // manifest's type docblock): a substring would also match any other
        // fixture whose name merely CONTAINS this one, and the list is ordered
        // `b.id - a.id`, so `.first()` then silently picked one of them.
        const value = String(seed[step.within]);
        // The `has` locator is built from `page`, NOT from `region`, and that is
        // load-bearing rather than cosmetic. MEASURED on the live page with two
        // accreditation cards:
        //
        //   filter({ has: page.getByRole('main').getByRole('heading', …) })  → 0
        //   filter({ has: page.getByRole('heading', …) })                    → 1
        //
        // `has` is matched against the candidate element, and a locator rooted at
        // `main` no longer resolves inside a candidate that IS inside main. A
        // silently empty `has` is exactly the kind of thing that gets "fixed" by
        // going back to `hasText` — which is the bug this whole change is about.
        const carrier = page.getByRole(step.withinRole ?? 'heading', { name: value, exact: true });
        // Two shapes, and the difference is which element gets clicked:
        //
        // - `withinContainer` omitted → the container (`article` card / `row`)
        //   SCOPES the click, and the control is looked up INSIDE it ("the
        //   Bearbeiten button of this template's row").
        // - `withinContainer` named → the container IS the control. The portal
        //   calendar's clickable card is an `<a>` with no control inside it, so
        //   looking for a link inside the link would never resolve (measured:
        //   a 120 s wait, not a strict-mode error — the quietest way to be
        //   wrong).
        if (step.withinContainer === undefined) {
            const containers = region.getByRole('article').or(region.getByRole('row'));
            locator = containers.filter({ has: carrier }).getByRole(step.role, name);
        } else {
            locator = region.getByRole(step.withinContainer).filter({ has: carrier });
        }
    }
    // No `.first()`: a locator that resolves to more than one element must fail
    // the run (Playwright strict mode) instead of quietly picking one.
    await locator.click();
    await waitForAppSettled(page);
}

/**
 * F6: the `empty.localhost` fixture tenant is unreachable in local dev (the
 * backend middleware resolves every local host to the primary mandant — see
 * `ui-review.config.ts` module header). For routes that must show a GENUINELY
 * empty UI, the manifest declares `emptyMock` entries: each matching request
 * is fulfilled with `{data: []}` (or the entry's custom `body`, e.g. an empty
 * portal overview) so the page renders its empty state. The stubs only ever
 * apply to `empty`-state captures and are scoped to the admin list endpoints
 * and the public portal routes — the login requests pass through untouched.
 */
async function stubEmptyLists(page: Page, route: UiReviewRoute, state: UiReviewState): Promise<void> {
    if (state !== 'empty') {
        return;
    }
    for (const entry of route.emptyMock ?? []) {
        const pattern = typeof entry === 'string' ? entry : entry.pattern;
        const body = typeof entry === 'string' ? { data: [] } : (entry.body ?? { data: [] });
        await page.route(pattern, (routeHandler) =>
            routeHandler.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) }),
        );
    }
}

for (const route of routes) {
    for (const state of route.states) {
        for (const viewport of route.viewports ?? ['desktop', 'mobile']) {
            test(`screenshot ${route.name} (${state}, ${viewport})`, { tag: ['@screenshot'] }, async ({ page }, testInfo) => {
                test.skip(
                    viewportForProject(testInfo.project.name) !== viewport,
                    `project ${testInfo.project.name} renders the ${viewportForProject(testInfo.project.name)} viewport`,
                );

                // Awaited by EVERY test, seeds or not: the dataset (reset +
                // fixtures) is built once per run, and a capture that ran before
                // the reset would see whatever the previous run left behind.
                const dataset = await uiReviewDataset();

                const origin = tenantOrigin(route, state);

                if (state === 'empty' && origin === EMPTY_MANDANT_ORIGIN) {
                    await ensureEmptyMandant();
                }
                const seed = route.seeds?.[state] ? await route.seeds[state]() : {};

                // F6 empty-state API stubs (before any navigation so SWR never
                // caches the real, primary-mandant data).
                await stubEmptyLists(page, route, state);

                // Initial guest load of "/" is the allowed page.goto exception.
                await page.goto(`${origin}/`);
                await waitForAppSettled(page);

                if (route.auth === 'admin') {
                    await loginViaUi(page, origin, ADMIN_EMAIL, ADMIN_PASSWORD, /\/admin/);
                } else if (route.auth === 'user') {
                    await loginViaUi(page, origin, String(seed.email), String(seed.password), /\/$/);
                }

                const needsMobileUrlBypass =
                    viewport === 'mobile' &&
                    (route.nav ?? []).some(
                        (step) => step.kind === 'click' && (step.scope === 'banner' || step.scope === 'complementary'),
                    );
                if (needsMobileUrlBypass) {
                    // Mobile-only bypass (see module comment case 3): the header
                    // links overflow the navbar at 360px, and the admin sidebar
                    // (`complementary`) sits behind the CLOSED daisyUI drawer
                    // (H5) so its links are absent from the a11y tree. Both are
                    // loaded by their resolved URL instead — deterministic, and
                    // the desktop path still clicks the landmarks' links.
                    await page.goto(resolvePath(route.path, seed));
                    await waitForAppSettled(page);
                } else {
                    for (const step of route.nav ?? []) {
                        await applyNavStep(page, step, seed);
                    }
                }

                await settleAndCapture(page, route, state, viewport, dataset, seed);
            });
        }
    }
}
