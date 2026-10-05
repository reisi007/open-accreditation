import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantAccreditation, registerAndActivateUser } from './helpers/admin-data';
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

/**
 * ## The 422 bodies the API now negotiates, read off the screen that shows them
 *
 * `messages.accreditations.already_applied` is the one refusal of the nine that
 * a user reaches WITHOUT any admin fixture: apply once, go back, apply again.
 * The SPA renders `err.message` verbatim (`ApplyPage.tsx`), so what this file
 * asserts is the whole chain — the app's active locale on `Accept-Language`
 * (`api/client.ts` → `logic/uiLocale.ts`), the backend's negotiation
 * (`SetRequestLocale`), the catalog (`backend/lang/{de,en}/messages.php`) and the
 * verbatim display.
 *
 * ### Why this is not the same test twice
 *
 * The English case runs with a **German BROWSER** (`test.use({ locale: 'de-DE' })`)
 * and an English app, because the browser is the confounder that makes the test
 * falsifiable: Chromium's own header is `de-DE` here, so if the SPA stopped
 * sending `Accept-Language` the backend would answer German and the English
 * assertion would fail. `admin-sub-resend.spec.ts` documents the measurement —
 * the first version of that test stayed green with the header deleted, because
 * the two languages agreed by accident.
 *
 * **Measured 2026-10-05: deleting `headers.set('Accept-Language', …)` from
 * `send()` turns BOTH of these red** (2 failed), where the resend pair's German
 * half survives as a control. The difference is the browser locale each half
 * runs on: this file's German half runs on Playwright's default Chromium
 * (`en-US`), so with the header gone the backend answers English there too. Both
 * halves are therefore gates on the header rather than one gate and one control
 * — which is a different trade from the resend file's, not a better one, and
 * stated here so nobody reads the two files as contradicting each other.
 *
 * ### The negated assertions are the point
 *
 * Each test also asserts the OTHER language is absent. Without them, a UI showing
 * a German sentence on an English page would pass the English case whenever the
 * German text happened to contain the English fragment.
 *
 * ### The navigation is inline, and why
 *
 * `tests/e2e/**` is linted with the plain-ES2020 parser, where a parameter
 * annotation anywhere outside the four listed typed modules is a PARSE ERROR
 * (`eslint.config.js` measures it). A shared `applyTwice(page: Page, …)` helper
 * would need one, so the flow is spelled out per test like
 * `admin-sub-resend.spec.ts` and `approvals.spec.ts` do.
 *
 * ### The visible names are per-locale and are NOT derived from the UI
 *
 * After the switch the nav link is `Accreditations`, not `Akkreditierungen`, the
 * card button is `Apply`, not `Beantragen`, and the submit button is `Apply for
 * accreditation`, not `Akkreditierung beantragen`. Every one of those is a
 * different string in `locales/en/messages.po`, so a test that looked the names
 * up in the page it is asserting on would find the English one and pass for the
 * wrong reason — and one that hardcoded German would fail in its navigation,
 * before the assertion it exists for.
 *
 * ### Ownership
 *
 * A FRESH user per test (`registerAndActivateUser`), never the seeded admin:
 * `accreditation.spec.ts` applies as `admin@example.com`, and a second spec doing
 * the same would race it across workers and leave a `requested` row that turns
 * that spec's own first apply into a 422. The account is registered with the
 * ledger inside the helper, and `applications.user_id` cascades on account
 * deletion — so the application created here goes with it. The last step still
 * WITHDRAWS the row through the owner route, because a cascade is a backstop and
 * not a receipt.
 */

test.describe('API-Meldungstexte: die Sprache der Anzeige (Antrag doppelt)', () => {
    // UI-heavy spec: run once (Desktop Chrome) to keep the shared per-IP login
    // throttle within budget alongside the other @feature specs.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test("a German app reads the refusal in German — the SERVER's German", { tag: ['@feature:accreditation', '@feature:i18n'] }, async ({ page }) => {
        const { accreditation, categoryName } = await ensurePrimaryMandantAccreditation();
        const user = await registerAndActivateUser();

        // (1) Enter through the GUEST apply link, so the login page carries
        //     `from=/apply/<id>` and returns here afterwards. Signing in from the
        //     nav instead sends a plain `user` to `/admin` (`LoginPage`'s default
        //     `from`), which bounces back to the portal home and — measured on the
        //     first run of this spec — races the next navigation into a page that
        //     is no longer the one under test.
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Akkreditierungen', exact: true }).click();
        await page.getByRole('main').locator('article', { hasText: categoryName })
            .getByRole('link', { name: 'Beantragen', exact: true })
            .click();
        await expect(page).toHaveURL(/\/login$/);

        const login = page.getByRole('main');
        await login.getByLabel('E-Mail', { exact: true }).fill(user.email);
        await login.getByLabel('Passwort', { exact: true }).fill(user.password);
        await login.getByRole('button', { name: 'Anmelden' }).click();

        await expect(page).toHaveURL(new RegExp(`/apply/${accreditation.id}$`));
        await page.getByRole('main').getByRole('button', { name: 'Akkreditierung beantragen' }).click();
        await expect(page.getByRole('main').getByText('Antrag erfolgreich gesendet.')).toBeVisible();

        // (2) Back to the same page. The success card links only to "Meine
        //     Akkreditierungen", so the way back is via the nav — SPA navigation
        //     by click, never a second `goto`.
        await page.getByRole('banner').getByRole('link', { name: 'Akkreditierungen', exact: true }).click();
        await page.getByRole('main').locator('article', { hasText: categoryName })
            .getByRole('link', { name: 'Beantragen', exact: true })
            .click();
        await expect(page).toHaveURL(new RegExp(`/apply/${accreditation.id}$`));
        await page.getByRole('main').getByRole('button', { name: 'Akkreditierung beantragen' }).click();

        const alert = page.getByRole('main').getByRole('alert');
        await expect(alert).toContainText('Du hast dich für diese Akkreditierung bereits beworben.');
        // The English literal this body used to be, verbatim: the change shows
        // up as the ABSENCE of it, not only as the presence of German.
        await expect(alert).not.toContainText('You have already applied');

        // Hand the row back through the owner route rather than leaning on the
        // account cascade (§7 fixture ownership).
        await page.getByRole('banner').getByRole('link', { name: 'Meine Akkreditierungen', exact: true }).click();
        await page.getByRole('main').locator('article', { hasText: categoryName })
            .getByRole('button', { name: 'Zurückziehen' })
            .click();
        await expect(page.getByRole('main').locator('article', { hasText: categoryName })).toHaveCount(0);
    });

    test.describe('with a German BROWSER and an English APP', () => {
        // `locale` drives `navigator.language` AND the browser's own
        // `Accept-Language`, so it is what makes the disagreement real.
        test.use({ locale: 'de-DE' });

        test('an English app reads the refusal in English — only the explicit header can do that', { tag: ['@feature:accreditation', '@feature:i18n'] }, async ({ page }) => {
            const { accreditation, categoryName } = await ensurePrimaryMandantAccreditation();
            const user = await registerAndActivateUser();

            // The guest leg runs in the UI's boot language (German); the switch
            // happens right after login, before the refusal is ever requested.
            await page.goto('/');
            await page.getByRole('banner').getByRole('link', { name: 'Akkreditierungen', exact: true }).click();
            await page.getByRole('main').locator('article', { hasText: categoryName })
                .getByRole('link', { name: 'Beantragen', exact: true })
                .click();
            await expect(page).toHaveURL(/\/login$/);

            const login = page.getByRole('main');
            await login.getByLabel('E-Mail', { exact: true }).fill(user.email);
            await login.getByLabel('Passwort', { exact: true }).fill(user.password);
            await login.getByRole('button', { name: 'Anmelden' }).click();
            await expect(page).toHaveURL(new RegExp(`/apply/${accreditation.id}$`));

            // Switch the UI FIRST. The locale is app state on the shared Lingui
            // singleton, so every later request carries `Accept-Language: en`;
            // doing it after the refusal would prove nothing. The control is
            // labelled in the CURRENT UI language, so it reads 'Sprache' here and
            // 'Language' afterwards.
            await page.getByRole('banner').getByRole('combobox', { name: 'Sprache' }).selectOption('en');
            await expect(page.getByRole('banner').getByRole('combobox', { name: 'Language' })).toHaveValue('en');

            await page.getByRole('main').getByRole('button', { name: 'Apply for accreditation' }).click();
            await expect(page.getByRole('main').getByText('Application submitted successfully.')).toBeVisible();

            await page.getByRole('banner').getByRole('link', { name: 'Accreditations', exact: true }).click();
            await page.getByRole('main').locator('article', { hasText: categoryName })
                .getByRole('link', { name: 'Apply', exact: true })
                .click();
            await expect(page).toHaveURL(new RegExp(`/apply/${accreditation.id}$`));
            await page.getByRole('main').getByRole('button', { name: 'Apply for accreditation' }).click();

            const alert = page.getByRole('main').getByRole('alert');
            // Byte-identical to `lang/en/messages.php` — and to the literal this
            // endpoint answered before the catalogs existed.
            await expect(alert).toContainText('You have already applied for this accreditation.');
            // No German leaks into an English reader.
            await expect(alert).not.toContainText('beworben');

            await page.getByRole('banner').getByRole('link', { name: 'My accreditations', exact: true }).click();
            await page.getByRole('main').locator('article', { hasText: categoryName })
                .getByRole('button', { name: 'Withdraw' })
                .click();
            await expect(page.getByRole('main').locator('article', { hasText: categoryName })).toHaveCount(0);
        });
    });
});
