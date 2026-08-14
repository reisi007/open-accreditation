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
 *   used for justified direct-URL routes (deep links / dynamic detail pages);
 *   each such route documents its reason in the manifest's `nav` step.
 * - Login flows through the real UI — no localStorage injection.
 * - Locators are scoped to landmarks (`banner` / `complementary` / `main`).
 */

const PRIMARY_ORIGIN = 'http://localhost:5173';
const ADMIN_EMAIL = 'admin@example.com';
const ADMIN_PASSWORD = 'admin';

function viewportForProject(projectName: string): UiReviewViewport {
    return projectName === 'Mobile Chrome' ? 'mobile' : 'desktop';
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

async function loginViaUi(page: Page, email: string, password: string): Promise<void> {
    await page.getByRole('banner').getByRole('link', { name: 'Anmelden', exact: true }).click();
    await expect(page).toHaveURL(/\/login$/);
    const main = page.getByRole('main');
    await main.getByLabel('E-Mail', { exact: true }).fill(email);
    await main.getByLabel('Passwort', { exact: true }).fill(password);
    await main.getByRole('button', { name: 'Anmelden', exact: true }).click();
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
}

async function settleAndCapture(
    page: Page,
    route: UiReviewRoute,
    state: UiReviewState,
    viewport: UiReviewViewport,
): Promise<void> {
    await page.waitForLoadState('networkidle');
    await expect(page.getByRole('main')).toBeVisible();
    // Small settle delay for fonts/images before the pixels are captured.
    await page.waitForTimeout(250);
    const file = path.resolve(process.cwd(), uiReviewConfig.outputDir, state, viewport, `${route.name}.png`);
    await page.screenshot({ path: file, fullPage: true });
}

for (const route of routes) {
    for (const state of route.states) {
        for (const viewport of route.viewports ?? ['desktop', 'mobile']) {
            test(`screenshot ${route.name} (${state}, ${viewport})`, { tag: ['@screenshot'] }, async ({ page }, testInfo) => {
                test.skip(
                    viewportForProject(testInfo.project.name) !== viewport,
                    `project ${testInfo.project.name} renders the ${viewportForProject(testInfo.project.name)} viewport`,
                );

                const origin = state === 'empty' ? EMPTY_MANDANT_ORIGIN : PRIMARY_ORIGIN;

                if (state === 'empty') {
                    await ensureEmptyMandant();
                }
                const seed = route.seed ? await route.seed() : {};

                // Initial guest load of "/" is the allowed page.goto exception.
                await page.goto(`${origin}/`);

                if (route.auth === 'admin') {
                    await loginViaUi(page, ADMIN_EMAIL, ADMIN_PASSWORD);
                    await expect(page).toHaveURL(/\/admin/);
                } else if (route.auth === 'user') {
                    await loginViaUi(page, String(seed.email), String(seed.password));
                }

                for (const step of route.nav ?? []) {
                    await applyNavStep(page, step, seed);
                }

                await settleAndCapture(page, route, state, viewport);
            });
        }
    }
}
