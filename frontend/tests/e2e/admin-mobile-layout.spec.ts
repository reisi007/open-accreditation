import { expect, test } from '@playwright/test';
import { FRONTEND_BASE_URL, loginAdminApi } from './helpers/admin-data';

/**
 * The two findings of the first Vision-Loop (2026-09-28) that are only
 * observable as GEOMETRY, asserted by measuring a real browser instead of by
 * reasoning about the markup.
 *
 * Plain ES2020 on purpose: `tests/e2e` is linted with the non-TS parser but built
 * by the strict `tsc -b`, so a type ANNOTATION is an ESLint parse error — and
 * because the file is `.ts` (not `.js`), a JSDoc `@param` does not type the
 * parameter either. That leaves exactly one honest option: **no helper with
 * parameters**. The shared steps are therefore inlined, and every nullable
 * value is narrowed with an explicit `throw` — `expect(x).not.toBeNull()` does
 * not narrow, and `!` is the forbidden escape hatch.
 *
 * Tagging: `@regression` + `@feature:admin:layout` + `@mobile`. NOT `@smoke` —
 * the header budget is not the critical path (login, guest, auth, basic CRUD),
 * and these specs open their own narrow context, so running them under both
 * Playwright projects would double the work for nothing. Hence the same
 * `testInfo.project.name` gate every other UI-heavy spec uses
 * (`a11y.spec.ts:25`), with the viewport created explicitly below
 * (`a11y.spec.ts:108` is the model). The Galaxy-A55 descriptor is 480 px wide;
 * 360 px is the width at which the header budget is actually tight.
 */

/** A mandant name long enough to push the switcher to its `max-w` ceiling. */
const LONG_MANDANT_NAME = 'E2E Akkreditierung w0-p86470-1790548253933 Verbandsname';

const ADMIN_ROUTES = [
    '/admin/categories',
    '/admin/events',
    '/admin/accreditations',
    '/admin/users',
    '/admin/badge-templates',
];

test.describe('Admin header fits the mobile viewport (P5)', () => {
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('every header control stays whole: nothing clipped, nothing off-viewport', { tag: ['@regression', '@feature:admin:layout', '@mobile'] }, async ({ browser }) => {
        // The defect this pins, measured BEFORE the fix on a Galaxy A55 (480 px)
        // as `super_admin`: daisyUI gives `.navbar-start`/`.navbar-end` a hard
        // `width: 50%`, so `navbar-end` received 232 px of a 464 px content box
        // while its children needed 315–391 px. The `<select>` was the only
        // child left with `flex-shrink`, so it collapsed from 88.9 px to 42 px
        // and clipped its own option text to "Deut…" on EVERY admin page.
        //
        // Note what is NOT asserted: that the document scrolls horizontally. It
        // never did — `scrollWidth === clientWidth` before and after. The header
        // starved its own control instead of overflowing, which is why the
        // finding read as "off-viewport". The clip measurement is the one that
        // actually discriminates between fixed and broken.
        for (const width of [480, 360]) {
            const context = await browser.newContext({ viewport: { width, height: 1040 } });
            const page = await context.newPage();
            try {
                await page.goto(`${FRONTEND_BASE_URL}/login`);
                const loginMain = page.getByRole('main');
                await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
                await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
                await loginMain.getByRole('button', { name: 'Anmelden' }).click();
                await expect(page).toHaveURL(/\/admin\//);

                for (const route of ADMIN_ROUTES) {
                    await page.goto(`${FRONTEND_BASE_URL}${route}`);
                    await page.waitForLoadState('networkidle');

                    const header = page.locator('header.navbar');
                    const select = header.locator('select');
                    await expect(select, `language select present at ${width}px ${route}`).toBeVisible();

                    // The regression itself: the select's own content must not be
                    // clipped. `scrollWidth > clientWidth` is exactly the "Deut…"
                    // condition, and it is measurable without a screenshot.
                    const clipped = await select.evaluate((el) => el.scrollWidth - el.clientWidth);
                    expect(clipped, `language select not clipped at ${width}px ${route} (was 47px)`).toBe(0);

                    // …and it must be FULLY inside the viewport, not merely
                    // present: the right edge of the box is what a user sees.
                    const selectBox = await select.boundingBox();
                    if (selectBox === null) throw new Error(`select has no box at ${width}px ${route}`);
                    expect(
                        selectBox.x + selectBox.width,
                        `select right edge within ${width}px on ${route}`,
                    ).toBeLessThanOrEqual(width);

                    // The brand must not have been crushed to a single letter in
                    // order to pay for it. With both halves at the default
                    // `shrink-1`, a 360 px viewport took the brand to 36.5 px
                    // (an "A" plus an ellipsis) while the switcher kept 116.6 px.
                    const brandBox = await header.locator('.navbar-start a').boundingBox();
                    if (brandBox === null) throw new Error(`brand has no box at ${width}px ${route}`);
                    expect(brandBox.width, `brand keeps a usable width at ${width}px ${route} (was 36.5px)`).toBeGreaterThan(100);

                    // Belt and braces: the header itself must not be a scroll
                    // container that pushes controls out of sight.
                    const headerOverflow = await header.evaluate((el) => el.scrollWidth - el.clientWidth);
                    expect(headerOverflow, `header does not overflow its own box at ${width}px ${route}`).toBe(0);
                }
            } finally {
                await context.close();
            }
        }
    });

    test('a long mandant name does not push the language select out of the header', { tag: ['@regression', '@feature:admin:layout', '@mobile'] }, async ({ browser }) => {
        // The other half of the finding: `MandantSwitcher`'s `max-w-48` is a
        // MAX, so with the real ("Hauptseite") fixture the trigger is only
        // 116.6 px and the bug is invisible. The worst case has to be produced
        // deliberately — here by rewriting the mandant name in the
        // `/api/admin/mandants` RESPONSE, which changes the layout without
        // writing to the shared dev database.
        const context = await browser.newContext({ viewport: { width: 360, height: 1040 } });
        const page = await context.newPage();
        try {
            await page.route('**/api/admin/mandants*', async (route) => {
                const response = await route.fetch();
                const body = await response.json();
                if (Array.isArray(body.data)) {
                    for (const mandant of body.data) {
                        if (typeof mandant.name === 'string' && mandant.name.length < LONG_MANDANT_NAME.length) {
                            mandant.name = LONG_MANDANT_NAME;
                        }
                    }
                }
                await route.fulfill({ response, json: body });
            });

            await page.goto(`${FRONTEND_BASE_URL}/login`);
            const loginMain = page.getByRole('main');
            await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
            await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
            await loginMain.getByRole('button', { name: 'Anmelden' }).click();
            await expect(page).toHaveURL(/\/admin\//);

            await page.goto(`${FRONTEND_BASE_URL}/admin/accreditations`);
            await page.waitForLoadState('networkidle');

            const select = page.locator('header.navbar select');
            const clipped = await select.evaluate((el) => el.scrollWidth - el.clientWidth);
            expect(clipped, 'select not clipped even with a 47-character mandant name at 360px').toBe(0);

            const selectBox = await select.boundingBox();
            if (selectBox === null) throw new Error('select has no box with a long mandant name');
            expect(
                selectBox.x + selectBox.width,
                'select fully inside a 360px viewport with a long name',
            ).toBeLessThanOrEqual(360);

            // The switcher may truncate its own label — that is by design
            // (`truncate` on the label span, full name in `aria-label`), so it
            // is not asserted. What must survive is the document not scrolling
            // and the select not being cut.
            const documentOverflow = await page.evaluate(
                () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
            );
            expect(documentOverflow, 'no horizontal document scroll with a long mandant name').toBe(0);

            // The full name is still announced, so truncating it costs nothing
            // for assistive tech.
            const label = await page.locator('header.navbar .dropdown > button').getAttribute('aria-label');
            expect(label, 'switcher keeps the full mandant name in aria-label').toContain(LONG_MANDANT_NAME);
        } finally {
            await context.close();
        }
    });
});

test.describe('Badge template actions are reachable on mobile (P6)', () => {
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('edit and delete are inside the viewport without horizontal scrolling', { tag: ['@regression', '@feature:admin:layout', '@feature:badge', '@mobile'] }, async ({ browser }) => {
        // P6: the actions column was the FOURTH of four and almost entirely
        // off-screen — the one finding that blocked the page's purpose rather
        // than its readability, since a template you cannot edit or delete on a
        // phone cannot be managed there at all.
        //
        // The assertion is a real geometry measurement (button box vs. viewport
        // edge), not a visibility check: `toBeVisible()` passes for an element
        // that is on-screen-but-cut, which is precisely the defect.
        // Named `E2E Ausweis …` on purpose: `purgeAllE2EArtifacts`
        // (`helpers/admin-data.ts:1017`) reclaims exactly the `E2E Ausweis*`
        // prefix on every teardown. A template under a name of its own would
        // survive a hard-killed run and show up as an extra row in the next
        // run's captures — the "stranded state" class the global setup exists
        // to clear. The `finally` block below still deletes it explicitly; this
        // is the belt to that pair of braces.
        const templateName = `E2E Ausweis Mobile ${Date.now()}`;
        const api = await loginAdminApi();
        let createdId = null;
        try {
            const created = await api.post('/api/admin/badge-templates', {
                data: {
                    name: templateName,
                    is_default: false,
                    layout: [{ field: 'name', x: 10, y: 10, w: 80, h: 10, size: 14, align: 'left' }],
                },
            });
            if (created.status() !== 201) {
                throw new Error(`Creating the badge template failed with status ${created.status()}`);
            }
            createdId = (await created.json()).data.id;
        } finally {
            await api.dispose();
        }

        const context = await browser.newContext({ viewport: { width: 480, height: 1040 } });
        const page = await context.newPage();
        try {
            await page.goto(`${FRONTEND_BASE_URL}/login`);
            const loginMain = page.getByRole('main');
            await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
            await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
            await loginMain.getByRole('button', { name: 'Anmelden' }).click();
            await expect(page).toHaveURL(/\/admin\//);

            await page.goto(`${FRONTEND_BASE_URL}/admin/badge-templates`);
            await page.waitForLoadState('networkidle');

            const row = page.getByRole('row', { name: new RegExp(templateName) });
            await expect(row).toBeVisible();

            /*
             * The mobile action row is the IMMEDIATE NEXT SIBLING of the data
             * row, not a row that repeats the template name: it holds only the
             * two buttons, so `hasText: templateName` matches nothing. Scoping by
             * adjacency is also the stronger assertion — it proves the pair
             * belongs to THIS template, which an index-based locator could not.
             */
            const actionsRow = row.locator('xpath=following-sibling::tr[1]');
            await expect(actionsRow).toHaveClass(/lg:hidden/);
            // Sanity: the sibling really is a mobile action row and the page has
            // not silently lost the actions.
            await expect(page.locator('tbody tr.lg\\:hidden').first()).toBeAttached();

            for (const name of ['Bearbeiten', 'Löschen']) {
                const button = actionsRow.getByRole('button', { name });
                await expect(button, `${name} visible at 480px`).toBeVisible();

                const box = await button.boundingBox();
                if (box === null) throw new Error(`${name} has no box at 480px`);
                expect(box.x, `${name} starts inside the viewport`).toBeGreaterThanOrEqual(0);
                expect(
                    box.x + box.width,
                    `${name} ends inside the 480px viewport (was cut off before the fix)`,
                ).toBeLessThanOrEqual(480);
            }

            // The `Aktionen` column is gone below lg, so it is the DATA columns
            // that scroll — the intended, hinted behaviour — not the actions.
            await expect(page.getByRole('columnheader', { name: 'Aktionen' })).toBeHidden();

            // And the button still works: the fix moved it, it did not clone a
            // dead control.
            await actionsRow.getByRole('button', { name: 'Bearbeiten' }).click();
            const dialog = page.getByRole('dialog');
            await expect(dialog).toBeVisible();
            // Scoped to the dialog and to the textbox role: the editor's canvas
            // also carries a "Feld Name" control, so a bare `getByLabel('Name')`
            // is a strict-mode violation.
            await expect(dialog.getByRole('textbox', { name: /^Name/ })).toHaveValue(templateName);
        } finally {
            await context.close();
            if (createdId !== null) {
                const cleanup = await loginAdminApi();
                try {
                    await cleanup.delete(`/api/admin/badge-templates/${createdId}`);
                } finally {
                    await cleanup.dispose();
                }
            }
        }
    });

    test('the actions column is back at lg and up (no duplicated controls)', { tag: ['@regression', '@feature:admin:layout', '@feature:badge'] }, async ({ page }) => {
        // The counterpart, so the mobile row cannot silently become the ONLY
        // place the actions live: at desktop width the header and the in-table
        // cell are shown again and the mobile row is display:none — exactly one
        // visible pair, never two.
        await page.goto(`${FRONTEND_BASE_URL}/login`);
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        await page.goto(`${FRONTEND_BASE_URL}/admin/badge-templates`);
        await page.waitForLoadState('networkidle');

        await expect(page.getByRole('columnheader', { name: 'Aktionen' })).toBeVisible();
        // `lg:hidden` means display:none here — not merely empty. A row that
        // were still laid out would give the page two visible "Bearbeiten"
        // controls and break the name-uniqueness the other specs rely on.
        await expect(page.locator('tbody tr.lg\\:hidden').first()).toBeHidden();
    });
});
