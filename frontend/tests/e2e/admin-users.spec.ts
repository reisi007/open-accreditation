import { expect, request, test } from '@playwright/test';
import { ensurePrimaryMandantHasTeam } from './helpers/admin-data';
import { MailpitHelper } from './helpers/mailpit';

const FRONTEND_BASE_URL = 'http://localhost:5173';

test.describe('Admin: Benutzer (P2c)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login/register calls and redundant DOM interaction on the mobile project.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('edit user roles: user → team_admin → user', { tag: ['@smoke', '@feature:admin:users'] }, async ({ page }) => {
        const suffix = Date.now();
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
    });
});
