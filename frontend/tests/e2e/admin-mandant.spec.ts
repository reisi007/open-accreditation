import { expect, test } from '@playwright/test';

test.describe('Admin: Mandanten (P2a)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login calls and redundant DOM interaction on the mobile project.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('create mandant with domain and team', { tag: ['@smoke', '@feature:admin:mandant'] }, async ({ page }) => {
        const suffix = Date.now();
        const uniqueName = `E2E Mandant ${suffix}`;
        const uniqueSlug = `e2e-mandant-${suffix}`;
        const domainHostname = `${uniqueSlug}.test`;
        const teamName = `E2E Team ${suffix}`;
        const teamSlug = `e2e-team-${suffix}`;

        // Initial guest load is the only allowed page.goto.
        await page.goto('/');

        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();

        await expect(page).toHaveURL(/\/admin\/mandants$/);

        const adminMain = page.getByRole('main');
        await expect(adminMain.getByRole('heading', { level: 1, name: 'Mandanten' })).toBeVisible();

        // Create a new mandant.
        await adminMain.getByRole('link', { name: 'Neu' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants\/new$/);

        const createMain = page.getByRole('main');
        await createMain.getByLabel('Name', { exact: true }).fill(uniqueName);
        await createMain.getByLabel('Slug', { exact: true }).fill(uniqueSlug);
        // Teams are an opt-in feature — enable them so the team step below works.
        await createMain.getByLabel('Teams aktivieren', { exact: true }).check();
        await createMain.getByRole('button', { name: 'Mandant erstellen' }).click();

        await expect(page).toHaveURL(/\/admin\/mandants\/\d+$/);
        await expect(page.getByRole('main').getByRole('heading', { level: 1, name: uniqueName })).toBeVisible();

        // Add a domain.
        const detailMain = page.getByRole('main');
        await detailMain.getByLabel('Domain', { exact: true }).fill(domainHostname);
        await detailMain.getByRole('button', { name: 'Domain hinzufügen' }).click();
        await expect(detailMain.getByText(domainHostname)).toBeVisible();

        // Add a team.
        await detailMain.getByRole('button', { name: 'Team hinzufügen' }).click();
        await detailMain.getByLabel('Team-Name', { exact: true }).fill(teamName);
        await detailMain.getByLabel('Team-Slug', { exact: true }).fill(teamSlug);
        await detailMain.getByRole('button', { name: 'Team speichern' }).click();
        await expect(detailMain.getByText(teamName)).toBeVisible();

        // The new mandant shows up in the list.
        await page.getByRole('complementary').getByRole('link', { name: 'Mandanten' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants$/);
        await expect(page.getByRole('main').getByRole('row', { name: new RegExp(uniqueName) })).toBeVisible();
    });
});
