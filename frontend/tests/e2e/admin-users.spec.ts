import { expect, request, test } from '@playwright/test';
import { FRONTEND_BASE_URL, ensurePrimaryMandantHasTeam, uniqueSuffix } from './helpers/admin-data';
import { MailpitHelper } from './helpers/mailpit';
import { reclaimOwnedRows, rememberUnreclaimableUser, resetOwnedRows } from './helpers/ownership';
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


test.describe('Admin: Benutzer (P2c)', () => {

    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login/register calls and redundant DOM interaction on the mobile project.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('edit user roles: user → team_admin → user', { tag: ['@smoke', '@feature:admin:users'] }, async ({ page }) => {
        const suffix = uniqueSuffix();
        const email = `admin-users-${suffix}@example.test`;
        const password = 'SecurePassw0rd!';

        // Setup: a disposable user (registered with the `user` role and
        // activated like in auth.spec) plus a team for the team_admin step.
        const team = await ensurePrimaryMandantHasTeam();
        const api = await request.newContext({ baseURL: FRONTEND_BASE_URL });
        try {
            const register = await api.post('/api/auth/register', {
                data: { name: 'E2E Benutzerverwaltung', email, password, password_confirmation: password },
            });
            expect(register.status()).toBe(201);
            // The one kind with no delete route, registered so the teardown
            // COUNTS and NAMES it instead of the row being invisible. The role
            // assignments this test then makes hang off the same user, so there
            // is nothing else to register.
            rememberUnreclaimableUser(email);

            const mailpit = new MailpitHelper();
            const activationPath = await mailpit.extractActivationPath(email);
            const activation = await api.get(new URL(activationPath, FRONTEND_BASE_URL).toString());
            expect(activation.status()).toBe(200);
        } finally {
            await api.dispose();
        }

        // Initial guest load is the only allowed page.goto.
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();

        await expect(page).toHaveURL(/\/admin\/mandants$/);

        // Navigate to Benutzer (only visible for super_admin / mandant_admin).
        await page.getByRole('complementary').getByRole('link', { name: 'Benutzer' }).click();
        await expect(page).toHaveURL(/\/admin\/users$/);
        const adminMain = page.getByRole('main');
        await expect(adminMain.getByRole('heading', { level: 1, name: 'Benutzer' })).toBeVisible();

        // Search for the disposable user (debounced).
        await adminMain.getByLabel('Benutzer suchen').fill(email);
        const row = adminMain.getByRole('row', { name: new RegExp(email) });
        await expect(row).toBeVisible();

        // Result count reflects the filtered list.
        await expect(adminMain.getByText('1 Benutzer', { exact: true })).toBeVisible();

        // Step 1: the `user` role is the default for a freshly registered user.
        await row.getByRole('button', { name: 'Rollen bearbeiten' }).click();
        await expect(adminMain.getByRole('heading', { name: 'Rollen bearbeiten' })).toBeVisible();
        await expect(adminMain.getByLabel('Benutzer', { exact: true })).toBeChecked();
        await adminMain.getByRole('button', { name: 'Speichern' }).click();
        await expect(row.getByText('User', { exact: true })).toBeVisible();

        // Step 2: assign team_admin with a team.
        await row.getByRole('button', { name: 'Rollen bearbeiten' }).click();
        await adminMain.getByLabel('Team-Admin', { exact: true }).check();
        await adminMain.getByLabel('Team', { exact: true }).selectOption(String(team.id));
        await adminMain.getByRole('button', { name: 'Speichern' }).click();
        await expect(row.getByText(/Team Admin/)).toBeVisible();

        // Step 3: reset to `user`.
        await row.getByRole('button', { name: 'Rollen bearbeiten' }).click();
        await adminMain.getByLabel('Team-Admin', { exact: true }).uncheck();
        await adminMain.getByLabel('Benutzer', { exact: true }).check();
        await adminMain.getByRole('button', { name: 'Speichern' }).click();
        await expect(row.getByText(/Team Admin/)).toHaveCount(0);
        await expect(row.getByText('User', { exact: true })).toBeVisible();

        // A search without hits shows the search-specific empty state.
        await adminMain.getByLabel('Benutzer suchen').fill('kein-treffer@example.test');
        await expect(adminMain.getByText('Keine Benutzer für die Suche.', { exact: true })).toBeVisible();
    });
});
