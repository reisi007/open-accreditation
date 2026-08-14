import { expect, test } from '@playwright/test';
import {
    ensurePrimaryMandantAccreditation,
    registerAndApplyForAccreditation,
} from './helpers/admin-data';

test.describe('Admin Freigaben (P3e)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login/register calls and redundant DOM interaction on the mobile project.
    // Deliberately NOT @smoke — the shared per-IP login throttle (15/min)
    // budget must stay available for the parallel @feature:accreditation specs.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('approves, denies, revokes, blacklists and bulk-allocates applications', { tag: ['@feature:accreditation'] }, async ({ page }) => {
        // Setup: one accreditation (quota 5) with four throwaway applicants.
        const { accreditation } = await ensurePrimaryMandantAccreditation();
        const applicantA = await registerAndApplyForAccreditation(accreditation.id, 'E2E Antragsteller A');
        const applicantB = await registerAndApplyForAccreditation(accreditation.id, 'E2E Antragsteller B');
        const applicantC = await registerAndApplyForAccreditation(accreditation.id, 'E2E Antragsteller C');
        const applicantD = await registerAndApplyForAccreditation(accreditation.id, 'E2E Antragsteller D');

        // Direct admin-URL load is the allowed route-guard exception: as a
        // guest, RequireAdmin redirects to /login (state.from), and after the
        // login the SPA returns to /admin/freigaben. This avoids the public
        // portal calls of a landing-page load (the shared anonymous rate
        // limiter budget is tight in the parallel feature suite).
        await page.goto('/admin/freigaben');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/freigaben$/);

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { level: 1, name: 'Freigaben' })).toBeVisible();

        // 1) Blacklist tab: add the fourth applicant to the blacklist via the UI.
        await main.getByRole('tab', { name: 'Blacklist', exact: true }).click();
        await main.getByLabel('E-Mail', { exact: true }).fill(applicantD.email);
        await main.getByRole('button', { name: 'Blacklist-Eintrag anlegen' }).click();
        const blacklistRow = main.getByRole('row', { name: new RegExp(applicantD.email) });
        await expect(blacklistRow).toBeVisible();

        // 2) Anträge tab: filter by the accreditation and see all four rows.
        await main.getByRole('tab', { name: 'Anträge', exact: true }).click();
        await main.getByLabel('Akkreditierung', { exact: true }).selectOption(String(accreditation.id));
        const rowA = main.getByRole('row', { name: new RegExp(applicantA.email) });
        const rowB = main.getByRole('row', { name: new RegExp(applicantB.email) });
        const rowC = main.getByRole('row', { name: new RegExp(applicantC.email) });
        const rowD = main.getByRole('row', { name: new RegExp(applicantD.email) });
        await expect(rowA).toBeVisible();
        await expect(rowB).toBeVisible();
        await expect(rowC).toBeVisible();
        await expect(rowD).toBeVisible();

        // 3) Mark applicant A as VIP.
        await rowA.getByLabel('VIP').check();
        await expect(rowA.getByLabel('VIP')).toBeChecked();

        // 4) Deny applicant B (requested) with a required reason.
        await rowB.getByRole('button', { name: 'Ablehnen', exact: true }).click();
        const denyDialog = page.getByRole('dialog');
        await expect(denyDialog.getByRole('heading', { name: 'Antrag ablehnen' })).toBeVisible();
        await denyDialog.getByLabel('Begründung').fill('Zu viele Anträge.');
        await denyDialog.getByRole('button', { name: 'Ablehnen', exact: true }).click();
        await expect(rowB.getByText('Abgelehnt')).toBeVisible();
        await expect(rowB.getByText('Zu viele Anträge.')).toBeVisible();

        // 5) Single approve of applicant A.
        await rowA.getByRole('button', { name: 'Freigeben', exact: true }).click();
        await expect(rowA.getByText('Freigegeben')).toBeVisible();
        await expect(rowA.getByRole('button', { name: 'Freigabe entziehen' })).toBeVisible();

        // 6) Mass allocation "Alle freigeben": A is already approved and B is
        //    denied, C is eligible and D is blacklisted → 1 approved / 1
        //    denied / 1 skipped.
        await main.getByRole('button', { name: 'Alle freigeben' }).click();
        const resultStatus = main.getByRole('status');
        await expect(resultStatus).toContainText('Freigegeben: 1');
        await expect(resultStatus).toContainText('Abgelehnt: 1');
        await expect(resultStatus).toContainText('Übersprungen (Blacklist): 1');
        await expect(resultStatus).toContainText('Übersprungene Anträge sind auf der Blacklist und bleiben beantragt.');
        await expect(rowC.getByText('Freigegeben')).toBeVisible();
        await expect(rowD.getByText('Abgelehnt')).toBeVisible();
        await expect(rowD.getByText('Blacklist')).toBeVisible();

        // 7) Revoke applicant A's approval (deny with reason on an approved row).
        await rowA.getByRole('button', { name: 'Freigabe entziehen' }).click();
        const revokeDialog = page.getByRole('dialog');
        await expect(revokeDialog.getByRole('heading', { name: 'Freigabe entziehen' })).toBeVisible();
        await revokeDialog.getByLabel('Begründung').fill('Zurückgezogen.');
        await revokeDialog.getByRole('button', { name: 'Ablehnen', exact: true }).click();
        await expect(rowA.getByText('Abgelehnt')).toBeVisible();

        // 8) Remove the blacklist entry again (confirm dialog).
        await main.getByRole('tab', { name: 'Blacklist', exact: true }).click();
        page.on('dialog', (dialog) => void dialog.accept());
        await blacklistRow.getByRole('button', { name: 'Löschen' }).click();
        await expect(blacklistRow).toHaveCount(0);
    });

    test('admin creates, edits and deletes a sub-accreditation via the admin modal', { tag: ['@feature:accreditation'] }, async ({ page }) => {
        const { categoryName } = await ensurePrimaryMandantAccreditation();

        // Direct admin-URL load is the allowed route-guard exception: the
        // guest is redirected to /login and returns to /admin/accreditations
        // after the login (state.from). No landing-page portal calls.
        await page.goto('/admin/accreditations');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/accreditations$/);

        const adminMain = page.getByRole('main');
        await expect(adminMain.getByRole('heading', { level: 1, name: 'Akkreditierungen' })).toBeVisible();
        const accreditationRow = adminMain.getByRole('row', { name: new RegExp(categoryName) });
        await expect(accreditationRow).toBeVisible();

        // Open the admin sub-accreditation modal.
        await accreditationRow.getByRole('button', { name: 'Sub-Akkreditierungen' }).click();
        const listDialog = page
            .getByRole('dialog')
            .filter({ has: page.getByRole('heading', { name: 'Sub-Akkreditierungen' }) });
        await expect(listDialog).toBeVisible();

        // Create a park sub-accreditation with quota 3.
        await listDialog.getByRole('button', { name: 'Neu' }).click();
        const createDialog = page
            .getByRole('dialog')
            .filter({ has: page.getByRole('heading', { name: 'Neue Sub-Akkreditierung' }) });
        await createDialog.getByLabel('Quota').fill('3');
        await createDialog.getByRole('button', { name: 'Sub-Akkreditierung erstellen' }).click();

        const subArticle = listDialog.locator('article', { hasText: 'Parkkarte' });
        await expect(subArticle).toBeVisible();
        await expect(subArticle.getByText('Quota 3')).toBeVisible();

        // Edit the quota to 4.
        await subArticle.getByRole('button', { name: 'Bearbeiten' }).click();
        const editDialog = page
            .getByRole('dialog')
            .filter({ has: page.getByRole('heading', { name: 'Sub-Akkreditierung bearbeiten' }) });
        await editDialog.getByLabel('Quota').fill('4');
        await editDialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(subArticle.getByText('Quota 4')).toBeVisible();

        // Delete the sub-accreditation (confirm dialog).
        page.on('dialog', (dialog) => void dialog.accept());
        await subArticle.getByRole('button', { name: 'Löschen' }).click();
        await expect(subArticle).toHaveCount(0);
    });
});
