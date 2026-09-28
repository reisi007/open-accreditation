import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantWalletSetup } from './helpers/admin-data';
import { reclaimOwnedRows, resetOwnedRows } from './helpers/ownership';
// Per-test ownership (tests/e2e/helpers/ownership.ts): the ledger is emptied BEFORE
// the first create and drained AFTER every test, so a spec that dies half-way
// still gives back what it managed to build — three fixtures created, the fourth
// throws, the three go back. The serial globalTeardown stays as the net for a run
// that was KILLED before this hook could run: a different failure, needing a
// different net.
//
// At FILE scope, not inside a describe, on purpose: admin-mobile-layout.spec.ts
// has two describes, and a describe-scoped hook would have covered only the
// first — the exact "the teardown exists somewhere in this file" illusion the
// gate in namespace-isolation.spec.ts is meant to end. The teardown exits before
// its admin login when the ledger is empty, so a test that creates nothing pays
// nothing.
test.beforeEach(async () => {
    resetOwnedRows();
});
test.afterEach(async () => {
    await reclaimOwnedRows();
});


test.describe('Wallet Downloads (P6)', () => {

    // UI-heavy spec: run once (Desktop Chrome) to keep the shared per-IP login
    // throttle (15/min) within budget. The wallet downloads go through the API
    // client (so a failed pass surfaces as an error message instead of being
    // saved as a bogus file); the blob hand-off keeps the server-provided
    // `Content-Disposition` filename.
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
        // is its own group, so the Apple button here is unambiguous (the sub
        // section below carries its own "Apple Wallet" button).
        const walletGroup = card.getByRole('group', { name: 'Wallet-Downloads' });
        await expect(walletGroup.getByRole('button', { name: 'Apple Wallet' })).toBeVisible();
        await expect(walletGroup.getByRole('button', { name: 'Google Wallet' })).toBeVisible();
        await expect(
            card.getByText('Pass wird im Apple/Google-Wallet-Format heruntergeladen.'),
        ).toBeVisible();

        const appleDownloadPromise = page.waitForEvent('download');
        await walletGroup.getByRole('button', { name: 'Apple Wallet' }).click();
        const appleDownload = await appleDownloadPromise;
        expect(appleDownload.suggestedFilename()).toBe(`accreditation-${application.id}.pkpass`);
        await appleDownload.cancel();

        // Google Wallet → JSON download.
        const googleDownloadPromise = page.waitForEvent('download');
        await walletGroup.getByRole('button', { name: 'Google Wallet' }).click();
        const googleDownload = await googleDownloadPromise;
        expect(googleDownload.suggestedFilename()).toBe('wallet.json');
        await googleDownload.cancel();

        // A failed pass must surface as a visible message instead of silently
        // saving the error body — the wallet route answers 403 for a pass whose
        // mandant has no Apple credentials, which is exercised in the unit
        // tests; here we only assert the happy path stays a real download.
        expect(await main.getByRole('alert').count()).toBe(0);

        // Approved park sub-accreditation → Apple Wallet .pkpass download.
        const subSection = card.locator('section', { hasText: 'Parkkarte' });
        const subAppleButton = subSection.getByRole('button', { name: 'Apple Wallet' });
        await expect(subAppleButton).toBeVisible();

        const subDownloadPromise = page.waitForEvent('download');
        await subAppleButton.click();
        const subDownload = await subDownloadPromise;
        expect(subDownload.suggestedFilename()).toBe(`park-${subApplication.id}.pkpass`);
        await subDownload.cancel();
    });
});
