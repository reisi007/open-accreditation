import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantActivePortalEvent } from './helpers/admin-data';

test.describe('Portal (P3a)', () => {
    test('landing shows mandant, event list, team filter and event detail', { tag: ['@smoke', '@feature:accreditation'] }, async ({ page }) => {
        const { event, team, mandantName } = await ensurePrimaryMandantActivePortalEvent();

        // Initial guest load is the only allowed page.goto.
        await page.goto('/');

        const main = page.getByRole('main');

        // Mandant name is the portal heading.
        await expect(main.getByRole('heading', { level: 1 })).toHaveText(mandantName);

        // The created event appears in the calendar.
        const eventLink = main.getByRole('link', { name: new RegExp(event.title) });
        await expect(eventLink).toBeVisible();

        // The team tile filters the calendar and syncs the team select.
        const teamTile = main.getByRole('button', { name: new RegExp(team.name) }).first();
        await expect(teamTile).toBeVisible();
        await teamTile.click();
        await expect(main.getByRole('combobox', { name: 'Team' })).toHaveValue(String(team.id));
        await expect(eventLink).toBeVisible();

        // Open the event detail.
        await eventLink.click();
        await expect(page).toHaveURL(new RegExp(`/events/${event.id}$`));

        const detailMain = page.getByRole('main');
        await expect(detailMain.getByRole('heading', { level: 1 })).toHaveText(event.title);
        await expect(detailMain.getByText('E2E Portal Arena')).toBeVisible();
        await expect(detailMain.getByText('E2E Wettbewerb')).toBeVisible();
        await expect(detailMain.getByText(/Noch \d+ Tage/)).toBeVisible();

        // Back to the calendar.
        await detailMain.getByRole('link', { name: 'Zurück zur Startseite' }).click();
        await expect(page).toHaveURL(/\/(?!events)/);
        await expect(main.getByRole('heading', { level: 1 })).toHaveText(mandantName);
        await expect(main.getByRole('link', { name: new RegExp(event.title) })).toBeVisible();
    });
});
