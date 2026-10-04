import { expect, request, test } from '@playwright/test';
import {
    FRONTEND_BASE_URL,
    ensurePrimaryMandantAccreditation,
    registerAndApplyForAccreditation,
    uniqueSuffix,
} from './helpers/admin-data';
import { MailpitHelper } from './helpers/mailpit';
import { reclaimOwnedRows, rememberOwnedUserAccount, resetOwnedRows } from './helpers/ownership';
import { throttleActorHeaders } from './helpers/throttle-actor';
// Per-test ownership (tests/e2e/helpers/ownership.ts): the ledger is emptied
// BEFORE the first create and drained AFTER every test, so a spec that dies
// half-way still gives back what it managed to build.
//
// For the self-deletion tests the ledger entry is the SAFETY NET, not the
// cleanup: the test deletes its own account through the UI, and the teardown
// then finds the account already gone and counts it as reclaimed (an account
// that is not there needs no delete). If a test dies BEFORE the delete, the
// teardown is the only thing between the run and one more orphaned account —
// which is what the account DELETE route closed the `users` gap for.
test.beforeEach(async () => {
    resetOwnedRows();
});
test.afterEach(async () => {
    await reclaimOwnedRows();
});

/**
 * Registers an account and activates it through the Mailpit delivery, so a UI
 * login can follow. Registered with the ledger BY EMAIL: `register` answers a
 * bare `{message}` and the teardown resolves the email to the id the account
 * DELETE route addresses.
 */
async function createActivatedAccount(prefix = 'account-delete') {
    const email = `${prefix}-${uniqueSuffix()}@example.test`;
    const password = 'SecurePassw0rd!';

    const api = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
    try {
        const register = await api.post('/api/auth/register', {
            data: { name: 'E2E Konto Person', email, password, password_confirmation: password },
        });
        expect(register.status()).toBe(201);
        rememberOwnedUserAccount(email);

        const mailpit = new MailpitHelper();
        const activationPath = await mailpit.extractActivationPath(email);
        // The link is built from APP_URL, which may not resolve locally.
        const activation = await api.get(new URL(activationPath, FRONTEND_BASE_URL).toString());
        expect(activation.status()).toBe(200);

        const login = await api.post('/api/auth/login', { data: { email, password } });
        expect(login.status()).toBe(200);

        return { api, email, password };
    } catch (error) {
        await api.dispose();
        throw error;
    }
}

// The UI login is written out at every call site rather than wrapped in a
// helper: `tests/e2e/**` is linted with the plain-JS parser (no annotations)
// while `tsc` is strict, and the only parameter shape that satisfies both is a
// DEFAULT value — which for a Playwright `Page` would be `null` and would then
// reject every real call site (MEASURED: TS2345 on all four call sites). The
// other specs in this directory inline the same five lines for the same reason.
//
// The first `page.goto('/')` of a test is the one allowed SPA load; every
// navigation after it is a click.

test.describe('Konto-Löschung (DSGVO)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // register/login calls and redundant DOM interaction on the mobile project.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    /**
     * The self-service area is the whole reason this feature exists in the UI:
     * before it, the decision "a user may delete their own account" had no
     * surface at all. The test drives it the way a user would — navbar link,
     * confirmation, delete — because a route reachable only by typing the URL
     * is not a surface.
     */
    test(
        'self-service: the confirmation names the account and the application count, and the delete removes it',
        { tag: ['@regression', '@feature:auth'] },
        async ({ page }) => {
            const { api, email, password } = await createActivatedAccount('self-delete');
            try {
                // Initial guest load is the only allowed page.goto.
                await page.goto('/');
                await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
                await expect(page).toHaveURL(/\/login$/);
                const loginMain = page.getByRole('main');
                await loginMain.getByLabel('E-Mail', { exact: true }).fill(email);
                await loginMain.getByLabel('Passwort', { exact: true }).fill(password);
                await loginMain.getByRole('button', { name: 'Anmelden' }).click();

                // Reached from the navbar, not from a URL.
                await page.getByRole('banner').getByRole('link', { name: 'Mein Konto' }).click();
                await expect(page).toHaveURL(/\/konto$/);
                const main = page.getByRole('main');
                await expect(main.getByRole('heading', { level: 1, name: 'Mein Konto' })).toBeVisible();

                // The area carries the account and its counts.
                await expect(main.getByText(email)).toBeVisible();
                await expect(main.getByText('Verband', { exact: true })).toBeVisible();
                await expect(main.getByText('0 Anträge', { exact: true })).toBeVisible();

                await main.getByRole('button', { name: 'Konto löschen' }).click();
                const dialog = page.getByRole('dialog');
                await expect(dialog).toBeVisible();
                // The mandatory content of the confirmation: the ACCOUNT …
                await expect(dialog.getByText(new RegExp(email.replace('.', '\\.')))).toBeVisible();
                // … and the NUMBER OF APPLICATIONS.
                await expect(dialog.getByTestId('confirm-applications')).toHaveText('0 Anträge');
                await expect(
                    dialog.getByText('Die Löschung ist endgültig und kann nicht rückgängig gemacht werden.'),
                ).toBeVisible();

                // Cancelling asks nothing of the server — the account is still there.
                await dialog.getByRole('button', { name: 'Abbrechen' }).click();
                await expect(dialog).toHaveCount(0);
                const afterCancel = await api.get('/api/auth/me');
                expect(afterCancel.status()).toBe(200);

                await main.getByRole('button', { name: 'Konto löschen' }).click();
                await page.getByRole('dialog').getByRole('button', { name: 'Endgültig löschen' }).click();

                // The session is over: the shell shows the public start page …
                await expect(page).toHaveURL(/\/$/);
                // … carrying the report of what went (counts measured in the
                // deletion transaction, so a stale zero cannot pass here).
                await expect(page.getByRole('alert').first()).toContainText('Dein Konto wurde gelöscht.');
                await expect(page.getByRole('banner').getByRole('link', { name: 'Anmelden' })).toBeVisible();

                // … and the account really is gone server-side: the SAME token
                // that authenticated the deletion now answers 401 (Weg A — the
                // row is gone, so no cache flush can bring it back).
                const afterDelete = await api.get('/api/auth/me');
                expect(afterDelete.status()).toBe(401);
                const account = await api.get('/api/user/account');
                expect(account.status()).toBe(401);
            } finally {
                await api.dispose();
            }
        },
    );

    /**
     * The confirmation has to name a NON-TRIVIAL application count, otherwise a
     * dialog that always reads "0 Anträge" would satisfy the requirement just
     * as well. So this account applies for something first and the dialog has to
     * name the real number.
     */
    test(
        'self-service: the confirmation names the real application count of an account that has one',
        { tag: ['@regression', '@feature:auth'] },
        async ({ page }) => {
            const accreditation = await ensurePrimaryMandantAccreditation();
            const { api, email, password } = await createActivatedAccount('self-delete-count');
            try {
                const apply = await api.post(`/api/accreditations/${accreditation.accreditation.id}/apply`);
                // 201, not 200 — MEASURED (the first run of this test asserted
                // 200 and failed on the status alone). A 409/422 here would mean
                // the account already holds an application, and the dialog would
                // then name a number this test did not create.
                expect(apply.status()).toBe(201);

                await page.goto('/');
                await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
                await expect(page).toHaveURL(/\/login$/);
                const loginMain = page.getByRole('main');
                await loginMain.getByLabel('E-Mail', { exact: true }).fill(email);
                await loginMain.getByLabel('Passwort', { exact: true }).fill(password);
                await loginMain.getByRole('button', { name: 'Anmelden' }).click();

                await page.getByRole('banner').getByRole('link', { name: 'Mein Konto' }).click();
                const main = page.getByRole('main');
                await expect(main.getByText('1 Antrag', { exact: true })).toBeVisible();

                await main.getByRole('button', { name: 'Konto löschen' }).click();
                const dialog = page.getByRole('dialog');
                await expect(dialog.getByTestId('confirm-applications')).toHaveText('1 Antrag');

                await dialog.getByRole('button', { name: 'Endgültig löschen' }).click();
                await expect(page).toHaveURL(/\/$/);
                // The count in the report is the one measured while deleting.
                await expect(page.getByRole('alert').first()).toContainText('1 Antrag');

                const afterDelete = await api.get('/api/auth/me');
                expect(afterDelete.status()).toBe(401);
            } finally {
                await api.dispose();
            }
        },
    );

    test(
        'self-service: a refused deletion leaves the account and the session untouched',
        { tag: ['@regression', '@feature:auth'] },
        async ({ page, context }) => {
            const { api, email, password } = await createActivatedAccount('self-delete-refused');
            try {
                // A route interceptor makes the DELETE fail the way a genuine
                // database failure would, so the error path is exercised against
                // the real component and the real dialog — not a mocked module.
                await context.route('**/api/user/account', async (route) => {
                    if (route.request().method() === 'DELETE') {
                        await route.fulfill({
                            status: 500,
                            contentType: 'application/json',
                            body: JSON.stringify({ message: 'Datenbank kaputt' }),
                        });
                        return;
                    }
                    await route.continue();
                });

                await page.goto('/');
                await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
                await expect(page).toHaveURL(/\/login$/);
                const loginMain = page.getByRole('main');
                await loginMain.getByLabel('E-Mail', { exact: true }).fill(email);
                await loginMain.getByLabel('Passwort', { exact: true }).fill(password);
                await loginMain.getByRole('button', { name: 'Anmelden' }).click();

                await page.getByRole('banner').getByRole('link', { name: 'Mein Konto' }).click();
                const main = page.getByRole('main');
                await main.getByRole('button', { name: 'Konto löschen' }).click();
                const dialog = page.getByRole('dialog');
                await dialog.getByRole('button', { name: 'Endgültig löschen' }).click();

                // The dialog stays open and says why — a silent failure would
                // read to the user as "deleted".
                await expect(dialog.getByRole('alert')).toContainText('Datenbank kaputt');
                await expect(page).toHaveURL(/\/konto$/);
                // Not logged out: the account is still there.
                await expect(page.getByRole('banner').getByRole('button', { name: 'Abmelden' })).toBeVisible();
                const stillThere = await api.get('/api/user/account');
                expect(stillThere.status()).toBe(200);
            } finally {
                await api.dispose();
            }
        },
    );

    /**
     * The admin side of the same decision. The button is gated by the role the
     * session actually carries (`users.delete`), and the confirmation names the
     * account and its application count — both measured by the server, never by
     * a client-side tally.
     */
    test(
        'admin: an account can be terminated from the user list and then really is gone',
        { tag: ['@regression', '@feature:admin:users'] },
        async ({ page }) => {
            const accreditation = await ensurePrimaryMandantAccreditation();
            // The applicant registers, applies and is registered with the
            // ledger, so the row under test has a NON-ZERO count to name and
            // the teardown can give the account back if this test dies early.
            const applicant = await registerAndApplyForAccreditation(accreditation.accreditation.id);
            const accountEmail = applicant.email;

            // Initial guest load is the only allowed page.goto.
            await page.goto('/');
            await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
            await expect(page).toHaveURL(/\/login$/);
            const loginMain = page.getByRole('main');
            await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
            await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
            await loginMain.getByRole('button', { name: 'Anmelden' }).click();
            await expect(page).toHaveURL(/\/admin\/mandants$/);

            await page.getByRole('complementary').getByRole('link', { name: 'Benutzer' }).click();
            await expect(page).toHaveURL(/\/admin\/users$/);
            const main = page.getByRole('main');
            await main.getByLabel('Benutzer suchen').fill(accountEmail);
            const row = main.getByRole('row', { name: new RegExp(accountEmail.replace('.', '\\.')) });
            await expect(row).toBeVisible();

            await row.getByRole('button', { name: 'Konto löschen' }).click();
            const dialog = page.getByRole('dialog');
            await expect(dialog.getByText(new RegExp(accountEmail.replace('.', '\\.')))).toBeVisible();
            await expect(dialog.getByTestId('confirm-applications')).toHaveText('1 Antrag');

            await dialog.getByRole('button', { name: 'Endgültig löschen' }).click();

            // The list is revalidated, so the row is gone rather than filtered
            // away locally — and the report names what went.
            await expect(row).toHaveCount(0);
            await expect(main.getByRole('alert').first()).toContainText('Das Konto wurde gelöscht.');
            await expect(main.getByRole('alert').first()).toContainText('1 Antrag');

            // Server-side proof through a fresh admin session: the account is
            // no longer a member of this mandant, so the list cannot find it.
            const verify = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
            try {
                const login = await verify.post('/api/auth/login', {
                    data: { email: 'admin@example.com', password: 'admin' },
                });
                expect(login.status()).toBe(200);
                const list = await verify.get(`/api/admin/users?search=${encodeURIComponent(accountEmail)}`);
                expect(list.status()).toBe(200);
                const rows = (await list.json()).data ?? [];
                // A loop, not `filter`: this directory may not ANNOTATE a
                // callback parameter (plain-JS parser) and may not leave one
                // un-annotated (strict tsc).
                const remaining = [];
                for (const found of rows) {
                    if (found.email === accountEmail) {
                        remaining.push(found);
                    }
                }
                expect(remaining).toHaveLength(0);

                // An unknown target is a 404, not a 500 — the mandant-scoped
                // route binding is what says so.
                const unknown = await verify.delete('/api/admin/users/2147483647');
                expect(unknown.status()).toBe(404);
            } finally {
                await verify.dispose();
            }
        },
    );
});
