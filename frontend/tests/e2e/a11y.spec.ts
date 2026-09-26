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
 * `transitionend`) and the "still inside after 25 Tab presses" focus-trap walk.
 * `AGENTS.md` §7 reserves `@smoke` for the critical path (login, guest, auth,
 * basic CRUD); a focus-trap assertion is not that, and a spec that has never run
 * in CI must not gate every push.
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
            await trigger.focus();
            await page.keyboard.press('Tab');
            let reached = false;
            for (let i = 0; i < 12 && !reached; i += 1) {
                reached = await trigger.evaluate((element) => element === document.activeElement);
                if (!reached) {
                    await page.keyboard.press('Tab');
                }
            }
            expect(reached).toBe(true);

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
