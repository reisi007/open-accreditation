import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantWalletSetup } from './helpers/admin-data';

test.describe('Wallet Downloads (P6)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to keep the shared per-IP login
    // throttle (15/min) within budget. The downloads are same-origin `<a
    // download>` links — cookie auth applies automatically, no blob handling.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('downloads Apple/Google wallet passes for an approved accreditation and its park sub-accreditation', { tag: ['@feature:wallet'] }, async ({ page }) => {
        // Setup: one approved main application AND one approved park
        // sub-application (apply + allocate via API, sub-apply after the
        // main is approved) for a throwaway user.
        const { application, categoryName, subApplication, user } = await ensurePrimaryMandantWalletSetup();

        // Initial guest load (allowed exception), then UI login as the user.
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill(user.email);
        await loginMain.getByLabel('Passwort', { exact: true }).fill(user.password);
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/$/);

        await page.getByRole('banner').getByRole('link', { name: 'Meine Akkreditierungen' }).click();
        await expect(page).toHaveURL(/\/meine-akkreditierungen$/);

        const main = page.getByRole('main');
        const card = main.locator('article', { hasText: categoryName });
        await expect(card).toBeVisible();
        // Two "Freigegeben" badges exist once the sub-application is approved
        // too (main header + sub section) — the first one is the main badge.
        await expect(card.getByText('Freigegeben').first()).toBeVisible();

        // Main accreditation → Apple Wallet .pkpass download. The wallet row
        // is its own group, so the Apple link here is unambiguous (the sub
        // section below carries its own "Apple Wallet" link).
        const walletGroup = card.getByRole('group', { name: 'Wallet-Downloads' });
        await expect(walletGroup.getByRole('link', { name: 'Apple Wallet' })).toBeVisible();
        await expect(walletGroup.getByRole('link', { name: 'Google Wallet' })).toBeVisible();
        await expect(
            card.getByText('Pass wird im Apple/Google-Wallet-Format heruntergeladen.'),
        ).toBeVisible();

        const appleDownloadPromise = page.waitForEvent('download');
        await walletGroup.getByRole('link', { name: 'Apple Wallet' }).click();
        const appleDownload = await appleDownloadPromise;
        expect(appleDownload.suggestedFilename()).toBe(`accreditation-${application.id}.pkpass`);
        await appleDownload.cancel();

        // Google Wallet → JSON download.
        const googleDownloadPromise = page.waitForEvent('download');
        await walletGroup.getByRole('link', { name: 'Google Wallet' }).click();
        const googleDownload = await googleDownloadPromise;
        expect(googleDownload.suggestedFilename()).toBe('wallet.json');
        await googleDownload.cancel();

        // Approved park sub-accreditation → Apple Wallet .pkpass download.
        const subSection = card.locator('section', { hasText: 'Parkkarte' });
        const subAppleLink = subSection.getByRole('link', { name: 'Apple Wallet' });
        await expect(subAppleLink).toBeVisible();

        const subDownloadPromise = page.waitForEvent('download');
        await subAppleLink.click();
        const subDownload = await subDownloadPromise;
        expect(subDownload.suggestedFilename()).toBe(`park-${subApplication.id}.pkpass`);
        await subDownload.cancel();
    });
});
