import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantAccreditation } from './helpers/admin-data';

test.describe('Accreditations (P3b)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login calls and redundant DOM interaction on the mobile project.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('guest applies, sees and withdraws an accreditation', { tag: ['@smoke', '@feature:accreditation'] }, async ({ page }) => {
        const { accreditation, categoryName } = await ensurePrimaryMandantAccreditation();

        // Initial guest load is the only allowed page.goto.
        await page.goto('/');

        // Open the public accreditation list from the nav.
        await page.getByRole('banner').getByRole('link', { name: 'Akkreditierungen', exact: true }).click();
        await expect(page).toHaveURL(/\/akkreditierungen$/);

        const main = page.getByRole('main');
        const card = main.locator('article', { hasText: categoryName });
        await expect(card).toBeVisible();
        await expect(card.getByText(/Plätze frei|Warteliste/)).toBeVisible();

        // Apply as a guest → the guard redirects to login.
        await card.getByRole('link', { name: 'Beantragen' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();

        // After login the user is sent back to the apply page.
        await expect(page).toHaveURL(new RegExp(`/apply/${accreditation.id}$`));

        const applyMain = page.getByRole('main');
        await expect(applyMain.getByRole('heading', { level: 1, name: 'Akkreditierung beantragen' })).toBeVisible();
        await applyMain.getByRole('button', { name: 'Akkreditierung beantragen' }).click();
        await expect(applyMain.getByText('Antrag erfolgreich gesendet.')).toBeVisible();

        // "Meine Akkreditierungen" shows the requested application.
        await applyMain.getByRole('link', { name: 'Meine Akkreditierungen' }).click();
        await expect(page).toHaveURL(/\/meine-akkreditierungen$/);

        const mineMain = page.getByRole('main');
        const mineCard = mineMain.locator('article', { hasText: categoryName });
        await expect(mineCard).toBeVisible();
        await expect(mineCard.getByText('Beantragt')).toBeVisible();

        // Withdraw the application → the entry disappears.
        await mineCard.getByRole('button', { name: 'Zurückziehen' }).click();
        await expect(mineCard).toHaveCount(0);
    });
});
