import { expect, request, test } from '@playwright/test';
import {
    FRONTEND_BASE_URL,
    allocateAccreditationApi,
    ensurePrimaryMandantSubAccreditation,
    registerAndActivateUser,
} from './helpers/admin-data';
import { reclaimOwnedRows, rememberOwnedByUser, resetOwnedRows } from './helpers/ownership';
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

/**
 * ## Position 47 — the sub-application resend button
 *
 * The counterpart of the main request's "E-Mail erneut senden" for Park-/Sitzkarte
 * sub-applications, on the surface where an admin actually decides a
 * sub-application: the "Sub-Anträge" tab of /admin/freigaben. Not a second place,
 * and not a guessed one — the admin who wants the sub-status mail sent again is
 * standing in that table.
 *
 * ## What is asserted, and what deliberately is NOT
 *
 * The success text is the SERVER's. MEASURED on the running backend,
 * `AdminSubApplicationController::resend` answers
 * `{"message": "E-Mail wurde erneut in die Warteschlange gestellt."}` and only
 * dispatches a `SendMandantMail` job — so a translated string of our own
 * ("erneut gesendet") would be a claim about a relay this process never talked
 * to. `not.toContainText('erneut gesendet.')` is therefore part of the contract:
 * a UI showing BOTH strings would still assert what it cannot know.
 *
 * The **422 is not driven here**, and that is a decision, not a gap: a `requested`
 * sub-application has no mailable status
 * (`AdminSubApplicationController::resend:186-210`), and the button is hidden on
 * exactly those rows — so the UI offers no way to reach the 422 at all. Test 1
 * pins that the button is ABSENT there, which is the stronger statement: the
 * error path is not merely handled, it is unreachable through this surface.
 *
 * ## Why the navigation is inline
 *
 * `tests/e2e/**` is linted with the plain-ES2020 parser, so a helper taking
 * `(page: Page)` is a PARSE ERROR here (measured, and the reason the typed
 * modules in `eslint.config.js` are listed file by file). The login/navigation is
 * therefore spelled out per test, like `approvals.spec.ts` and `badge.spec.ts`
 * do, rather than hidden in a helper this directory cannot type.
 */

/**
 * A `requested` sub-application of a fresh sub-accreditation, plus the account
 * that holds it. Everything it creates is registered with the ownership ledger
 * before the next step, so a later throw still gives the rows back.
 */
async function createRequestedSubApplication() {
    const { accreditation, subAccreditation } = await ensurePrimaryMandantSubAccreditation();
    const user = await registerAndActivateUser();

    const userApi = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
    try {
        const login = await userApi.post('/api/auth/login', {
            data: { email: user.email, password: user.password },
        });
        expect(login.status()).toBe(200);

        // The main application first: the sub-apply contract requires an
        // APPROVED main (the same order `ensurePrimaryMandantWalletSetup` uses,
        // for the same reason).
        const mainApply = await userApi.post(`/api/accreditations/${accreditation.id}/apply`);
        // 200 or 201, like every other apply in this suite — the endpoint's
        // status varies with the allocation result, its id does not.
        expect([200, 201]).toContain(mainApply.status());
        const mainBody = await mainApply.json();
        expect(mainBody.data.accreditation.id).toBe(accreditation.id);
        rememberOwnedByUser('applications', mainBody.data.id, user.email, user.password);
    } finally {
        await userApi.dispose();
    }

    const allocation = await allocateAccreditationApi(accreditation.id, 'all');
    expect(allocation.approved).toBe(1);

    const subApi = await request.newContext({ baseURL: FRONTEND_BASE_URL, extraHTTPHeaders: throttleActorHeaders() });
    try {
        const login = await subApi.post('/api/auth/login', { data: { email: user.email, password: user.password } });
        expect(login.status()).toBe(200);
        const subApply = await subApi.post(`/api/sub-accreditations/${subAccreditation.id}/apply`);
        expect(subApply.status()).toBe(201);
        const subBody = await subApply.json();
        expect(subBody.data.sub_accreditation.id).toBe(subAccreditation.id);
        // Registered here, while it is still `requested`: the owner route can
        // withdraw it in this state and answers 422 once it is decided — the
        // teardown then hands the row to the cascade and proves afterwards that
        // the cascade took it (`verifiedBy` in `E2E_OWNED_TEARDOWN`).
        rememberOwnedByUser('subApplications', subBody.data.id, user.email, user.password);
    } finally {
        await subApi.dispose();
    }

    return { subAccreditation, user };
}

test.describe('Admin Sub-Antrag E-Mail erneut senden (Position 47)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to keep the shared per-IP login
    // throttle (15/min) within budget alongside the other @feature specs.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('resends the approval mail of an approved sub-application and shows the server message', { tag: ['@feature:admin:sub-resend', '@smoke'] }, async ({ page }) => {
        const { subAccreditation, user } = await createRequestedSubApplication();

        // `page.goto('/')` is the allowed guest exception; everything after it is
        // SPA navigation by click (frontend/AGENTS.md, "No page.goto").
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden', exact: true }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants$/);

        // `complementary` is the ARIA role of the nav's `<aside>`; the drawer
        // aside holds the same links but is hidden at this viewport, so the click
        // resolves to the visible one.
        await page.getByRole('complementary').getByRole('link', { name: 'Freigaben', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/freigaben$/);

        const main = page.getByRole('main');
        await main.getByRole('tab', { name: 'Sub-Anträge' }).click();
        await main.getByLabel('Sub-Akkreditierung', { exact: true }).selectOption(String(subAccreditation.id));

        // 1) A `requested` sub-application offers NO resend button — the backend
        //    can only mail `approved`/`denied` and answers 422 otherwise.
        const row = main.getByRole('row', { name: new RegExp(user.email) });
        await expect(row).toBeVisible();
        await expect(row.getByRole('button', { name: 'E-Mail erneut senden' })).toHaveCount(0);

        // 2) Approve it here, on the surface the button lives on.
        await row.getByRole('button', { name: 'Freigeben', exact: true }).click();
        await expect(row.getByText('Freigegeben')).toBeVisible();

        // 3) Resend → the SERVER's message (the queueing claim), never a
        //    delivered-mail claim of our own.
        const resendButton = row.getByRole('button', { name: 'E-Mail erneut senden' });
        await expect(resendButton).toBeVisible();
        await resendButton.click();
        const status = row.getByRole('status');
        await expect(status).toContainText('E-Mail wurde erneut in die Warteschlange gestellt.');
        await expect(status).not.toContainText('erneut gesendet.');
    });

    test('resends the denial mail of a denied sub-application', { tag: ['@feature:admin:sub-resend'] }, async ({ page }) => {
        const { subAccreditation, user } = await createRequestedSubApplication();

        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden', exact: true }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants$/);
        await page.getByRole('complementary').getByRole('link', { name: 'Freigaben', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/freigaben$/);

        const main = page.getByRole('main');
        await main.getByRole('tab', { name: 'Sub-Anträge' }).click();
        await main.getByLabel('Sub-Akkreditierung', { exact: true }).selectOption(String(subAccreditation.id));

        const row = main.getByRole('row', { name: new RegExp(user.email) });
        await expect(row).toBeVisible();

        // Deny with a reason — the reason is what makes the row MAILABLE, so a
        // denied row without one would answer 422 on the resend.
        await row.getByRole('button', { name: 'Ablehnen', exact: true }).click();
        const denyDialog = page.getByRole('dialog');
        await denyDialog.getByLabel('Begründung').fill('Zu viele Anträge.');
        await denyDialog.getByRole('button', { name: 'Ablehnen', exact: true }).click();
        await expect(row.getByText('Abgelehnt')).toBeVisible();

        const resendButton = row.getByRole('button', { name: 'E-Mail erneut senden' });
        await expect(resendButton).toBeVisible();
        await resendButton.click();
        await expect(row.getByRole('status')).toContainText('E-Mail wurde erneut in die Warteschlange gestellt.');
        await expect(row.getByRole('status')).not.toContainText('erneut gesendet.');
    });
});