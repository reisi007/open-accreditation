import { expect, request, test } from '@playwright/test';
import { FRONTEND_BASE_URL, ensurePrimaryMandantHasTeam, uniqueSuffix } from './helpers/admin-data';
import { loginAdminApi } from './helpers/api-session';
import { MailpitHelper } from './helpers/mailpit';
import { reclaimOwnedRows, rememberOwnedUserAccount, resetOwnedRows } from './helpers/ownership';
import { throttleActorHeaders } from './helpers/throttle-actor';
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
        const api = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
        try {
            const register = await api.post('/api/auth/register', {
                data: { name: 'E2E Benutzerverwaltung', email, password, password_confirmation: password },
            });
            expect(register.status()).toBe(201);
            // Registered by email: `register` answers no id, and the teardown
            // resolves the email to the id the account DELETE route addresses.
            // The role assignments this test then makes ride the CASCADE from
            // the user delete, so there is nothing else to register.
            rememberOwnedUserAccount(email);

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

    /**
     * F4 (Nutzerentscheid 2026-10-06): `users.manage` (role assignment) and
     * `users.delete` (account termination) are two permissions, and a
     * `team_admin` holds the first but not the second.
     *
     * This is the end-to-end shape of that split, in the REAL browser session:
     * he reaches the Benutzer page through the nav, the role editor offers him
     * exactly the one role he may write, and there is no delete action anywhere
     * on the page. The last assertion goes past the UI on purpose — it drives
     * the DELETE route with HIS OWN cookie, so the 403 comes from the backend
     * gate rather than from a button that was never rendered. A UI-only test
     * would stay green if the separation were enforced by hiding a button while
     * the route answered 200.
     *
     * Ownership (§7): the account is registered BY EMAIL in the ledger the
     * moment it exists, so `afterEach` gives it back even if a later step dies.
     * Its `team_admin` assignment rides the CASCADE from the account delete, so
     * nothing else is left behind. The TEAM is the shared bootstrap row from
     * `ensurePrimaryMandantHasTeam()` and is deliberately NOT registered —
     * `namespace-isolation.spec.ts` documents why a shared row must not belong
     * to one test.
     */
    test('team admin: assigns roles in his own team, and no delete action exists', { tag: ['@regression', '@feature:admin:users'] }, async ({ page }) => {
        const team = await ensurePrimaryMandantHasTeam();
        const suffix = uniqueSuffix();
        const email = `admin-users-team-${suffix}@example.test`;
        const password = 'SecurePassw0rd!';

        const api = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
        let userId = 0;
        try {
            const register = await api.post('/api/auth/register', {
                // The display name deliberately does NOT contain "Team Admin":
                // the row assertion below matches the ROLE BADGE, and a display
                // name that matches the same regex makes the locator ambiguous
                // (strict-mode violation).
                data: { name: 'E2E Klubleitung', email, password, password_confirmation: password },
            });
            expect(register.status()).toBe(201);
            rememberOwnedUserAccount(email);

            const mailpit = new MailpitHelper();
            const activationPath = await mailpit.extractActivationPath(email);
            const activation = await api.get(new URL(activationPath, FRONTEND_BASE_URL).toString());
            expect(activation.status()).toBe(200);
        } finally {
            await api.dispose();
        }

        // Promote him. `register` answers no id, so the id is resolved through
        // the admin list (search by the unique address) rather than guessed —
        // and the admin context is the one that may read that list at all.
        const adminApi = await loginAdminApi();
        try {
            const list = await adminApi.get(`/api/admin/users?search=${encodeURIComponent(email)}`);
            expect(list.status()).toBe(200);
            const rows = (await list.json()).data ?? [];
            expect(rows).toHaveLength(1);
            userId = rows[0].id;

            const promote = await adminApi.put(`/api/admin/users/${userId}/roles`, {
                data: { roles: [{ role: 'team_admin', team_id: team.id }] },
            });
            expect(promote.status()).toBe(200);
        } finally {
            await adminApi.dispose();
        }

        // Initial guest load is the only allowed page.goto.
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill(email);
        await loginMain.getByLabel('Passwort', { exact: true }).fill(password);
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();

        // The nav offers him Benutzer (`users.manage`) and NOT the Verband-level
        // surfaces he holds no permission for.
        const nav = page.getByRole('complementary');
        await expect(nav.getByRole('link', { name: 'Benutzer' })).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Logo & Header' })).toHaveCount(0);
        await nav.getByRole('link', { name: 'Benutzer' }).click();
        await expect(page).toHaveURL(/\/admin\/users$/);

        const adminMain = page.getByRole('main');
        await expect(adminMain.getByRole('heading', { level: 1, name: 'Benutzer' })).toBeVisible();

        // His own row is on his team's roster, and it carries the role editor.
        const row = adminMain.getByRole('row', { name: new RegExp(email) });
        await expect(row).toBeVisible();
        await expect(row.getByRole('button', { name: 'Rollen bearbeiten' })).toBeVisible();

        // The delete action does not exist — for ANY row, not just his own.
        await expect(adminMain.getByRole('button', { name: 'Konto löschen' })).toHaveCount(0);

        // The editor offers the one role he may write, and says why the others
        // are missing. A checkbox the API answers 403 to is not offered.
        await row.getByRole('button', { name: 'Rollen bearbeiten' }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByLabel('Team-Admin', { exact: true })).toBeVisible();
        await expect(dialog.getByLabel('Mandant-Admin', { exact: true })).toHaveCount(0);
        await expect(dialog.getByLabel('Verifizierer', { exact: true })).toHaveCount(0);
        await expect(dialog.getByText(/nur innerhalb deines Teams/)).toBeVisible();

        // Saving his own team_admin assignment succeeds — the scoped write is
        // allowed, so the page is not a dead end for him either.
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(row.getByText(/Team Admin/)).toBeVisible();

        // Past the UI, with HIS session: the delete route refuses him. This is
        // the assertion that would fail if the separation were enforced by
        // hiding a button while the route answered 200.
        const del = await page.request.delete(`/api/admin/users/${userId}`);
        expect(del.status()).toBe(403);

        const stillThere = await page.request.get('/api/admin/users');
        expect(stillThere.status()).toBe(200);
        const remaining = (await stillThere.json()).data ?? [];
        // A `for…of`, not `.some(u => …)`: this directory is linted with the
        // PLAIN-JS parser, where a callback parameter would be an implicit `any`
        // under `tsc`. See the module docblock in helpers/ownership.ts.
        let stillListed = false;
        for (const entry of remaining) {
            if (entry.id === userId) {
                stillListed = true;
            }
        }
        expect(stillListed).toBe(true);
    });
});
