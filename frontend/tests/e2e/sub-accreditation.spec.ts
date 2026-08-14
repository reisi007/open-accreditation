import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantSubAccreditation, loginAdminApi, registerAndActivateUser } from './helpers/admin-data';

test.describe('Sub-Accreditations (P3d)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to keep the shared per-IP login
    // throttle (15/min) within budget across the parallel @feature:accreditation
    // specs. The spec needs 5 logins in total (2 API setup + 3 UI).
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('user applies for a park sub-accreditation and withdraws it', { tag: ['@feature:accreditation'] }, async ({ page }) => {
        const { accreditation, categoryName } = await ensurePrimaryMandantSubAccreditation();
        const user = await registerAndActivateUser();

        // 1) User applies for the main accreditation (guest → login → apply).
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Akkreditierungen', exact: true }).click();
        await expect(page).toHaveURL(/\/akkreditierungen$/);

        const listMain = page.getByRole('main');
        const card = listMain.locator('article', { hasText: categoryName });
        await expect(card).toBeVisible();
        await card.getByRole('link', { name: 'Beantragen' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill(user.email);
        await loginMain.getByLabel('Passwort', { exact: true }).fill(user.password);
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(new RegExp(`/apply/${accreditation.id}$`));

        const applyMain = page.getByRole('main');
        await expect(applyMain.getByRole('heading', { level: 1, name: 'Akkreditierung beantragen' })).toBeVisible();
        await applyMain.getByRole('button', { name: 'Akkreditierung beantragen' }).click();
        await expect(applyMain.getByText('Antrag erfolgreich gesendet.')).toBeVisible();

        // 2) "Meine Akkreditierungen" shows the requested main application.
        await applyMain.getByRole('link', { name: 'Meine Akkreditierungen' }).click();
        await expect(page).toHaveURL(/\/meine-akkreditierungen$/);

        const mineMain = page.getByRole('main');
        const mineCard = mineMain.locator('article', { hasText: categoryName });
        await expect(mineCard).toBeVisible();
        await expect(mineCard.getByText('Beantragt')).toBeVisible();

        // 3) Logout, then approve the main application as admin (API allocation
        // `mode=all` — same contract as the P3c UI trigger).
        await page.getByRole('banner').getByRole('button', { name: 'Abmelden' }).click();
        await expect(page).toHaveURL(/\/$/);

        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);
        const adminLoginMain = page.getByRole('main');
        await adminLoginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await adminLoginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await adminLoginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants$/);

        const adminApi = await loginAdminApi();
        try {
            const allocate = await adminApi.post(`/api/admin/accreditations/${accreditation.id}/allocate`, {
                data: { mode: 'all' },
            });
            expect(allocate.status()).toBe(200);
        } finally {
            await adminApi.dispose();
        }

        await page.getByRole('banner').getByRole('button', { name: 'Abmelden' }).click();
        await expect(page).toHaveURL(/\/$/);

        // 4) Back as the user: the main is approved, the sub section is visible.
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);
        const userLoginMain = page.getByRole('main');
        await userLoginMain.getByLabel('E-Mail', { exact: true }).fill(user.email);
        await userLoginMain.getByLabel('Passwort', { exact: true }).fill(user.password);
        await userLoginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/$/);

        await page.getByRole('banner').getByRole('link', { name: 'Meine Akkreditierungen' }).click();
        await expect(page).toHaveURL(/\/meine-akkreditierungen$/);

        const mine2 = page.getByRole('main');
        const approvedCard = mine2.locator('article', { hasText: categoryName });
        await expect(approvedCard).toBeVisible();
        await expect(approvedCard.getByText('Freigegeben')).toBeVisible();

        const subSection = approvedCard.locator('section', { hasText: 'Parkkarte' });
        await expect(
            subSection.getByRole('heading', { level: 3, name: 'Sub-Akkreditierungen (Park/Sitz)' }),
        ).toBeVisible();
        await expect(subSection.getByText('Noch 1 frei')).toBeVisible();

        // 5) Apply for the Parkkarte → the requested badge replaces the button.
        await subSection.getByRole('button', { name: 'Beantragen' }).click();
        await expect(subSection.getByText('Beantragt')).toBeVisible();
        await expect(subSection.getByRole('button', { name: 'Zurückziehen' })).toBeVisible();
        await expect(subSection.getByRole('button', { name: 'Beantragen' })).toHaveCount(0);

        // 6) Withdraw → the apply button is back.
        await subSection.getByRole('button', { name: 'Zurückziehen' }).click();
        await expect(subSection.getByRole('button', { name: 'Zurückziehen' })).toHaveCount(0);
        await expect(subSection.getByRole('button', { name: 'Beantragen' })).toBeVisible();
    });
});
