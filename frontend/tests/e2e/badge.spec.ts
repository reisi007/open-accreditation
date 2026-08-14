import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantApprovedApplication } from './helpers/admin-data';

test.describe('Badge-Templates, Export & Verify (P4)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login/register calls. Deliberately NOT @smoke — the shared per-IP login
    // throttle budget stays available for the parallel @feature:accreditation
    // specs.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('creates a template, exports PDF badges and verifies a token publicly', { tag: ['@feature:badge'] }, async ({ page }) => {
        // Setup: one accreditation with one approved application (portrait
        // uploaded, apply + allocate via API). The approved row carries the
        // relative verify URL `/verify/<token>`.
        const { accreditation, application } = await ensurePrimaryMandantApprovedApplication();
        const verifyToken = application.qr_url.split('/').pop();

        // Direct admin-URL load is the allowed route-guard exception: as a
        // guest, RequireAdmin redirects to /login (state.from) and the SPA
        // returns to /admin/badge-templates after the login.
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { level: 1, name: 'Ausweis-Templates' })).toBeVisible();

        // Create a badge template via the UI: name + three layout fields
        // (name, category, date) and mark it as the mandant default.
        // When no templates exist yet, "Neu" appears in the header AND in the
        // empty-state panel CTA — the header one (first) is equivalent.
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Neues Template' })).toBeVisible();

        await dialog.getByLabel('Name', { exact: true }).fill('E2E Ausweis');
        await dialog.getByLabel('Standard-Template').check();
        await dialog.getByRole('button', { name: 'Feld hinzufügen' }).click();
        await dialog.getByLabel('Feld Typ').nth(1).selectOption('category');
        await dialog.getByRole('button', { name: 'Feld hinzufügen' }).click();
        await dialog.getByLabel('Feld Typ').nth(2).selectOption('date');

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        const templateRow = main.getByRole('row', { name: /E2E Ausweis/ });
        await expect(templateRow).toBeVisible();
        await expect(templateRow.getByText('Standard')).toBeVisible();
        await expect(templateRow.getByText('3 Felder')).toBeVisible();

        // Navigate (SPA) to the approvals view and export the approved badges
        // as PDF from the accreditation's Ausweis-Export section.
        await page.getByRole('complementary').getByRole('link', { name: 'Freigaben', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/freigaben$/);
        await main.getByLabel('Akkreditierung', { exact: true }).selectOption(String(accreditation.id));

        const downloadPromise = page.waitForEvent('download');
        await main.getByRole('button', { name: 'PDF', exact: true }).click();
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toBe(`badges-${accreditation.id}.pdf`);
        // No `saveAs` — the fixture keeps the download in memory; cancelling
        // releases it cleanly instead of letting the harness drop it.
        await download.cancel();

        // Public verify page: opening the approved application's qr_url (guest
        // page.goto is the allowed route exception) shows the status, identity
        // and the streamed portrait.
        await page.goto(`/verify/${verifyToken}?token=${verifyToken}`);
        const verifyMain = page.getByRole('main');
        await expect(verifyMain.getByText('Akkreditiert', { exact: true })).toBeVisible();
        await expect(verifyMain.getByText('E2E Badge Inhaber')).toBeVisible();
        await expect(verifyMain.getByRole('img', { name: 'Foto' })).toBeVisible();

        // A tampered/unknown token shows the invalid-code state.
        await verifyMain.getByLabel('Code', { exact: true }).fill('ungueltig');
        await verifyMain.getByRole('button', { name: 'Prüfen', exact: true }).click();
        await expect(verifyMain.getByText('Ungültiger Code.')).toBeVisible();
    });
});
