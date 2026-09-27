import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantHasTeam, uniqueSuffix } from './helpers/admin-data';

test.describe('Admin: Events (P2b)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login calls and redundant DOM interaction on the mobile project.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('create, edit and delete an event with team venue default', { tag: ['@smoke', '@feature:admin:event'] }, async ({ page }) => {
        const suffix = uniqueSuffix();
        const uniqueTitle = `E2E Event ${suffix}`;
        const editedTitle = `${uniqueTitle} bearbeitet`;

        // Setup: the current mandant needs a team with a home venue for the
        // venue-default assertion.
        const team = await ensurePrimaryMandantHasTeam();

        // Initial guest load is the only allowed page.goto.
        await page.goto('/');

        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();

        await expect(page).toHaveURL(/\/admin\/mandants$/);

        // Navigate to Events.
        await page.getByRole('complementary').getByRole('link', { name: 'Events' }).click();
        await expect(page).toHaveURL(/\/admin\/events$/);
        const adminMain = page.getByRole('main');
        await expect(adminMain.getByRole('heading', { level: 1, name: 'Events' })).toBeVisible();

        // Create an event and verify the venue default from the team.
        await adminMain.getByRole('button', { name: 'Neu' }).click();
        await adminMain.getByLabel('Titel', { exact: true }).fill(uniqueTitle);
        await adminMain.getByLabel('Team', { exact: true }).selectOption(String(team.id));
        if (team.venue) {
            // The combobox shows the RESOLVED venue name for the id the team
            // carries, so the readable assertion stays the same.
            await expect(adminMain.getByRole('combobox', { name: 'Spielort' })).toHaveValue(team.venue.name);
        }
        await adminMain.getByLabel('Datum', { exact: true }).fill('2026-09-01');
        await adminMain.getByLabel('Frist Beginn', { exact: true }).fill('2026-08-01');
        await adminMain.getByLabel('Frist Ende', { exact: true }).fill('2026-08-20');
        await adminMain.getByRole('button', { name: 'Event erstellen' }).click();
        await expect(adminMain.getByRole('row', { name: new RegExp(uniqueTitle) })).toBeVisible();

        // Edit the event. The row locator must not be built from `uniqueTitle`:
        // `editedTitle` contains it, so a `RegExp(uniqueTitle)` also matches the
        // unchanged row and a failed PUT would pass unnoticed. The title cell is
        // asserted by its exact text instead — the old title must be gone.
        await adminMain.getByRole('row', { name: new RegExp(uniqueTitle) }).getByRole('button', { name: 'Bearbeiten' }).click();
        await adminMain.getByLabel('Titel', { exact: true }).fill(editedTitle);
        await adminMain.getByRole('button', { name: 'Speichern' }).click();
        await expect(adminMain.getByText(editedTitle, { exact: true })).toBeVisible();
        await expect(adminMain.getByText(uniqueTitle, { exact: true })).toHaveCount(0);
        // A failed update keeps the modal open with an error — assert it is gone.
        await expect(adminMain.getByRole('button', { name: 'Speichern' })).toHaveCount(0);

        // Delete the event (confirm dialog).
        page.on('dialog', (dialog) => void dialog.accept());
        await adminMain.getByRole('row', { name: new RegExp(editedTitle) }).getByRole('button', { name: 'Löschen' }).click();
        await expect(adminMain.getByRole('row', { name: new RegExp(editedTitle) })).toHaveCount(0);
    });
});
