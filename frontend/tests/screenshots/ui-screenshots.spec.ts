import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import path from 'node:path';
import process from 'node:process';
import { routes, uiReviewConfig } from './ui-review.config';
import type { UiReviewNavStep, UiReviewRoute, UiReviewState, UiReviewViewport } from './ui-review.config';
import { EMPTY_MANDANT_ORIGIN, ensureEmptyMandant } from './helpers/empty-mandant';

/**
 * Generic manifest-driven screenshot spec for the ui-review skill.
 *
 * Iterates the route × state × viewport matrix from `ui-review.config.ts` and
 * captures a full-page PNG per combination. All tests are tagged `@screenshot`
 * so the set is groupable and clearly separate from the functional E2E tags.
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
 * - Locators are scoped to landmarks (`banner` / `complementary` / `main`).
 */

const PRIMARY_ORIGIN = 'http://localhost:5173';
const ADMIN_EMAIL = 'admin@example.com';
const ADMIN_PASSWORD = 'admin';

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

/** Let the SPA + i18n settle so later clicks never race a layout shift. */
async function waitForAppSettled(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300);
}

/**
 * UI login. The login page itself is loaded by direct URL (see the module
 * comment — the header "Anmelden" link is unreachable in the mobile navbar,
 * so the deep link is the only reliable route there). After the submit the
 * auth redirect chain must finish before any nav step runs: a user lands on
 * "/" (the admin redirect bounces them back), an admin on "/admin/*".
 */
async function loginViaUi(page: Page, origin: string, email: string, password: string, landing: RegExp): Promise<void> {
    await page.goto(`${origin}/login`);
    await expect(page).toHaveURL(/\/login$/);
    const main = page.getByRole('main');
    await main.getByLabel('E-Mail', { exact: true }).fill(email);
    await main.getByLabel('Passwort', { exact: true }).fill(password);
    await main.getByRole('button', { name: 'Anmelden', exact: true }).click();
    await expect(page).toHaveURL(landing);
    await waitForAppSettled(page);
}

async function applyNavStep(page: Page, step: UiReviewNavStep, seed: Record<string, unknown>): Promise<void> {
    if (step.kind === 'goto') {
        // Direct-URL load — only for justified routes (deep links / dynamic
        // detail pages); the manifest's `reason` documents each case.
        await page.goto(resolvePath(step.path, seed));
        return;
    }

    const region = page.getByRole(step.scope);
    let locator = region.getByRole(step.role, step.name !== undefined ? { name: step.name, exact: true } : undefined);
    if (step.within !== undefined) {
        locator = region
            .locator('article', { hasText: String(seed[step.within]) })
            .getByRole(step.role, { name: step.name, exact: true });
    }
    await locator.first().click();
    await waitForAppSettled(page);
}

async function settleAndCapture(
    page: Page,
    route: UiReviewRoute,
    state: UiReviewState,
    viewport: UiReviewViewport,
): Promise<void> {
    await waitForAppSettled(page);
    await expect(page.getByRole('main')).toBeVisible();
    const file = path.resolve(process.cwd(), uiReviewConfig.outputDir, state, viewport, `${route.name}.png`);
    await page.screenshot({ path: file, fullPage: true });
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

                await settleAndCapture(page, route, state, viewport);
            });
        }
    }
}
