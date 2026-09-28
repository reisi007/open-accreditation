import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantAccreditation } from './helpers/admin-data';

/**
 * Accessibility contract of the admin overlays (a11y review 2026-09-26).
 *
 * These assertions CANNOT be made in jsdom: jsdom implements neither
 * `HTMLDialogElement.showModal()` nor the UA's top-layer blocking, so the real
 * browser is the only place where "the background is inert" and "Tab stays
 * inside the dialog" are observable.
 *
 * Tagging: `@regression` + `@feature:a11y`, deliberately NOT `@smoke`. Two of
 * the three tests depend on timing that this spec cannot control — the 100 ms
 * daisyUI `visibility` transition delay (the focus hand-off lands on
 * `transitionend`) and the focus-trap walk inside a real `<dialog>`.
 * `AGENTS.md` §7 reserves `@smoke` for the critical path (login, guest, auth,
 * basic CRUD); a focus-trap assertion is not that, and a spec that has never run
 * in CI must not gate every push.
 *
 * A note for whoever touches the keyboard assertions: prefer a statement about
 * **order** over a statement about a **count**. The drawer test used to encode a
 * distance (tab until you are back where you started, at most N times), and N
 * turned out to be a property of the seeded test data. See the comment there.
 */
test.describe('Admin a11y: dialog semantics and drawer keyboard access', () => {
    // UI-heavy spec: run once (Desktop Chrome) — the shared per-IP login
    // throttle (15/min) budget must stay available for the parallel feature
    // specs. The drawer test opens its own 390px context below.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('modal is a real dialog: inert background, contained focus, Escape restores the trigger', { tag: ['@regression', '@feature:a11y'] }, async ({ page }) => {
        // Direct admin-URL load is the allowed route-guard exception.
        await page.goto('/admin/categories');
        await expect(page).toHaveURL(/\/login$/);
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/categories$/);

        const trigger = page.getByRole('button', { name: 'Neu' }).first();
        await trigger.click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        // Real <dialog> state (daisyUI styles `.modal[open]` identically to the
        // old `.modal-open` class, so the look is unchanged).
        await expect(dialog).toHaveAttribute('open', '');
        await expect(dialog).toHaveAttribute('aria-modal', 'true');

        // `showModal()` puts the dialog in the top layer, so the page behind it
        // is inert: the trigger can no longer be clicked.
        await trigger.click({ timeout: 1500 }).catch(() => undefined);
        await expect(page.getByRole('dialog')).toBeVisible();

        // Focus moved into the dialog and Tab is contained by the top layer.
        const inside = () =>
            page.evaluate(() => {
                const active = document.activeElement;
                const dialogElement = document.querySelector('dialog');
                return active !== null && dialogElement !== null && dialogElement.contains(active);
            });
        expect(await inside()).toBe(true);
        // 25 is a WALK LENGTH here, not a reachability threshold — do not
        // "optimise" it down. The postcondition is monotone: another Tab can only
        // strengthen the containment claim, never break it, and a dialog with
        // fewer stops than presses simply wraps inside itself. The drawer test
        // below had the opposite construct (a *distance* with a ceiling), which
        // is why its number had to go.
        for (let i = 0; i < 25; i += 1) {
            await page.keyboard.press('Tab');
        }
        expect(await inside()).toBe(true);

        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog')).toHaveCount(0);
        // Focus went back to the trigger instead of dropping to <body>.
        await expect(trigger).toBeFocused();
    });

    test('stacked modals: the lower dialog is inert and not dimmed twice', { tag: ['@regression', '@feature:a11y'] }, async ({ page }) => {
        const { categoryName } = await ensurePrimaryMandantAccreditation();

        await page.goto('/admin/accreditations');
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/accreditations$/);

        const adminMain = page.getByRole('main');
        const row = adminMain.getByRole('row', { name: new RegExp(categoryName) });
        await expect(row).toBeVisible();
        await row.getByRole('button', { name: 'Sub-Akkreditierungen' }).click();

        const listDialog = page.getByRole('dialog').filter({ hasText: 'Sub-Akkreditierungen' }).first();
        await expect(listDialog).toBeVisible();
        // Alone, the list dialog carries daisyUI's dark overlay.
        await expect(listDialog).not.toHaveClass(/bg-transparent/);

        await listDialog.getByRole('button', { name: 'Neu' }).click();

        const formDialog = page.getByRole('dialog').filter({ hasText: 'Neue Sub-Akkreditierung' });
        await expect(formDialog).toBeVisible();
        // While the form dialog is on top, the list must not dim the page a
        // second time …
        await expect(listDialog).toHaveClass(/bg-transparent/);
        // … and its controls must not stay reachable.
        await expect(listDialog.getByRole('button', { name: 'Neu' })).toBeDisabled();

        await page.keyboard.press('Escape');
        await expect(page.getByRole('dialog').filter({ hasText: 'Neue Sub-Akkreditierung' })).toHaveCount(0);
        await expect(listDialog).toBeVisible();
        await expect(listDialog).not.toHaveClass(/bg-transparent/);
    });

    test('admin drawer is keyboard reachable below lg and closes on Escape', { tag: ['@regression', '@feature:a11y', '@mobile'] }, async ({ browser }) => {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const page = await context.newPage();
        try {
            await page.goto('/admin/categories');
            const loginMain = page.getByRole('main');
            await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
            await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
            await loginMain.getByRole('button', { name: 'Anmelden' }).click();
            await expect(page).toHaveURL(/\/admin\/categories$/);

            const trigger = page.getByRole('button', { name: 'Menü' });
            await expect(trigger).toHaveAttribute('aria-expanded', 'false');
            await expect(trigger).toHaveAttribute('aria-controls', 'admin-drawer-nav');
            // Closed: the mobile <aside> is `visibility: hidden` (daisyUI), so
            // the desktop `lg:block` one is the only `complementary` landmark.
            await expect(page.getByRole('complementary')).toHaveCount(0);

            // Reachable with the keyboard alone (the old `<label htmlFor>` was
            // neither focusable nor a control).
            //
            // ANCHORED, NOT COUNTED. The previous version focused the trigger and
            // then tabbed up to 12 times waiting to land back on it — i.e. it
            // measured the size of the page's whole tab ring. That ring is a
            // function of the DATA, not of the header. Measured on
            // /admin/categories, one variable at a time (empty categories table,
            // then 1, 2, 3 and 4 rows), the distance back to the trigger was
            // 9 / 11 / 13 / 15 / 17 presses — **two presses per table row**, and
            // the old bound of 12 was already exceeded at **two rows**. The seed
            // helpers create a category per call (`ensurePrimaryMandantAccreditation`),
            // so the row count is decided by which other tests seeded first: the
            // assertion was at the mercy of its own suite, and a failure named the
            // feature. (Reproduced: 4 rows → the old form fails, this one passes,
            // same database, same build.)
            //
            // The claim is unchanged — reachable with the keyboard, no mouse — but
            // it is now a statement about the HEADER'S ORDER: the trigger is the
            // stop directly after the header's brand link, and nothing lies
            // between them. One Tab press, whatever the table holds. That has no
            // ceiling to grow into.
            //
            // The brand link is also the honest anchor: measured, it is the first
            // focusable element in the document (nothing precedes it, and a Tab
            // that runs off the end wraps through `body` back to it), so for a
            // keyboard user starting at the top this is literally "Tab, Tab".
            const header = page.getByRole('banner');
            await header.getByRole('link', { name: 'Akkreditierung' }).focus();
            await page.keyboard.press('Tab');
            await expect(trigger).toBeFocused();

            await page.keyboard.press('Enter');
            await expect(trigger).toHaveAttribute('aria-expanded', 'true');
            await expect(page.getByRole('complementary')).toHaveCount(1);

            // daisyUI transitions the drawer's `visibility` with a 100 ms delay,
            // so the focus hand-off lands on `transitionend`.
            await expect
                .poll(
                    () =>
                        page.evaluate(() => {
                            const active = document.activeElement;
                            const nav = document.getElementById('admin-drawer-nav');
                            return active !== null && nav !== null && nav.contains(active);
                        }),
                    { timeout: 5000 },
                )
                .toBe(true);

            await page.keyboard.press('Escape');
            await expect(trigger).toHaveAttribute('aria-expanded', 'false');
            await expect(trigger).toBeFocused();
            await expect(page.getByRole('complementary')).toHaveCount(0);
        } finally {
            await context.close();
        }
    });
});
