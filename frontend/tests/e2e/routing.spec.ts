import { expect, test } from '@playwright/test';

/**
 * Unmatched URLs and render errors must stay INSIDE the app shell with
 * translated copy. Without a catch-all route and an `errorElement` React Router
 * renders its own default error page, outside the header/footer and unstyled.
 *
 * Tagging: `@regression` + `@feature:routing`, deliberately NOT `@smoke`. The
 * error-boundary test needs a full login round-trip (three of these tests log in
 * within a shared 15/min per-IP throttle) and the two 404 tests depend on the
 * route manifest, not on the critical path `AGENTS.md` §7 defines as `@smoke`.
 */
test.describe('Routing: 404 and error boundary (a11y review 2026-09-26)', () => {
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('an unmatched public URL renders the translated 404 inside the shell', { tag: ['@regression', '@feature:routing'] }, async ({ page }) => {
        await page.goto('/gibt-es-nicht');

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { level: 1, name: 'Seite nicht gefunden.' })).toBeVisible();
        await expect(main.getByText('Die angeforderte Seite existiert nicht.')).toBeVisible();
        await expect(page.getByRole('banner')).toBeVisible();
        await expect(main.getByRole('link', { name: 'Zur Startseite' })).toBeVisible();
    });

    test('an unmatched admin URL renders the translated 404 inside the admin shell', { tag: ['@regression', '@feature:routing'] }, async ({ page }) => {
        await page.goto('/admin/categories');
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/categories$/);

        await page.getByRole('complementary').getByRole('link', { name: 'Kategorien' }).click();
        await page.goto('/admin/gibt-es-nicht');

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { level: 1, name: 'Seite nicht gefunden.' })).toBeVisible();
        await expect(page.getByRole('complementary')).toHaveCount(1);
    });

    test('a render error shows the translated errorElement, not the router default page', { tag: ['@regression', '@feature:routing'] }, async ({ page }) => {
        await page.goto('/admin/categories');
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/categories$/);

        // Malformed payload → the categories page throws while rendering.
        await page.route('**/api/admin/categories', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: { notAnArray: true } }),
            });
        });
        await page.reload();

        await expect(page.getByRole('alert').first()).toHaveText('Die Seite konnte nicht geladen werden.');
    });
});
