import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantHasTeam, uniqueSuffix } from './helpers/admin-data';
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


test.describe('Admin: Kategorien (P2b)', () => {

    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login calls and redundant DOM interaction on the mobile project.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('create, edit, override and delete categories', { tag: ['@smoke', '@feature:admin:category'] }, async ({ page }) => {
        const suffix = uniqueSuffix();
        const uniqueName = `E2E Kategorie ${suffix}`;
        const uniqueSlug = `e2e-kategorie-${suffix}`;
        const editedName = `${uniqueName} bearbeitet`;
        const editedSlug = `e2e-kategorie-${suffix}-v2`;
        const teamName = `E2E Team Kategorie ${suffix}`;
        const teamSlug = `e2e-team-kategorie-${suffix}`;

        // Setup: the current mandant needs a team for the team-level step.
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

        // Navigate to Kategorien.
        await page.getByRole('complementary').getByRole('link', { name: 'Kategorien' }).click();
        await expect(page).toHaveURL(/\/admin\/categories$/);
        const adminMain = page.getByRole('main');
        await expect(adminMain.getByRole('heading', { level: 1, name: 'Kategorien' })).toBeVisible();

        // Create a mandant-level category.
        await adminMain.getByRole('button', { name: 'Neu' }).click();
        await adminMain.getByLabel('Name', { exact: true }).fill(uniqueName);
        await adminMain.getByLabel('Slug', { exact: true }).fill(uniqueSlug);
        await adminMain.getByRole('button', { name: 'Kategorie erstellen' }).click();
        await expect(adminMain.getByRole('row', { name: new RegExp(uniqueName) })).toBeVisible();

        // Edit the category.
        await adminMain.getByRole('row', { name: new RegExp(uniqueSlug) }).getByRole('button', { name: 'Bearbeiten' }).click();
        await adminMain.getByLabel('Name', { exact: true }).fill(editedName);
        await adminMain.getByLabel('Slug', { exact: true }).fill(editedSlug);
        await adminMain.getByRole('button', { name: 'Speichern' }).click();
        await expect(adminMain.getByRole('row', { name: new RegExp(editedName) })).toBeVisible();

        // Create a team-level category (override).
        await adminMain.getByRole('button', { name: 'Neu' }).click();
        await adminMain.getByLabel('Name', { exact: true }).fill(teamName);
        await adminMain.getByLabel('Slug', { exact: true }).fill(teamSlug);
        await adminMain.getByLabel('Team', { exact: true }).selectOption(String(team.id));
        await adminMain.getByRole('button', { name: 'Kategorie erstellen' }).click();
        const teamRow = adminMain.getByRole('row', { name: new RegExp(teamName) });
        await expect(teamRow).toBeVisible();
        await expect(teamRow.getByText('Team-Override')).toBeVisible();

        // Delete both categories (confirm dialog).
        page.on('dialog', (dialog) => void dialog.accept());
        await adminMain.getByRole('row', { name: new RegExp(editedName) }).getByRole('button', { name: 'Löschen' }).click();
        await expect(adminMain.getByRole('row', { name: new RegExp(editedName) })).toHaveCount(0);
        await adminMain.getByRole('row', { name: new RegExp(teamName) }).getByRole('button', { name: 'Löschen' }).click();
        await expect(adminMain.getByRole('row', { name: new RegExp(teamName) })).toHaveCount(0);
    });
});
