import { expect, test } from '@playwright/test';
import { ensurePrimaryMandantApprovedApplication, loginAdminApi, uniqueSuffix } from './helpers/admin-data';
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
 * P4: badge template creation, the PDF export over the production route, and
 * the public verification of the exported badge's token.
 *
 * ## The cleanup rule this file used to break (and the measurement behind it)
 *
 * The template used to be reclaimed by a `test.afterAll` that swept every row
 * whose name started with `E2E Ausweis`. That is a MANDANT-WIDE prefix sweep in
 * a hook that is NOT serial, and it fired in the wrong project:
 *
 * - Playwright runs a file's `afterAll` once **per worker process that executed
 *   a test of that file** (`fullyParallel`, 8 workers).
 * - `test.skip()` inside `beforeEach` suppresses the test BODY only. The Mobile
 *   Chrome worker still "executed" the skipped test, so it still ran the
 *   `afterAll` — at an arbitrary moment during the run.
 *
 * MEASURED, three consecutive full runs: the log showed two `afterAll`
 * invocations in two different pids, the first of which deleted
 * `511:E2E Ausweis` **while the Desktop Chrome worker was between "create the
 * template" and "export the badges"**. The export then answered
 * `422 No badge template.`, the UI surfaced that as an alert, no download event
 * was ever emitted, and `page.waitForEvent('download')` burned the rest of the
 * test timeout — 2/2 red on a clean database, green in isolation. The throttling
 * theory was measured and disproved on the way: the 422 response carried
 * `x-ratelimit-remaining: 299` of 300.
 *
 * `badge-editor.spec.ts` had the same hook (`E2E Editor*`, measured firing 6-10
 * times per run) and `admin-mobile-layout.spec.ts` named its template
 * `E2E Ausweis Mobile …` — i.e. INSIDE this file's sweep prefix, so its row was
 * a legitimate target of a sweep that had nothing to do with it.
 *
 * The fix follows the rule `helpers/admin-data.ts` documents: **a per-worker
 * cleanup deletes by ID; a prefix sweep belongs only in the serial
 * `globalTeardown`.** This file therefore remembers the id of the row it
 * created and removes exactly that, and its marker stays registered in
 * `BADGE_TEMPLATE_PURGE_PREFIXES` so a crashed run's leftovers still die at the
 * end of the NEXT run.
 */

/** Names of the templates THIS run created, cleaned up in the `afterEach` below. */
const ownedTemplateNames = new Set();

/** The row this run created. Unique per run, so the lookup cannot be ambiguous. */
const TEMPLATE_NAME = `E2E Ausweis ${uniqueSuffix()}`;

test.describe('Badge-Templates, Export & Verify (P4)', () => {

    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login/register calls. Deliberately NOT @smoke — the shared per-IP login
    // throttle budget stays available for the parallel @feature:accreditation
    // specs.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    // Self-cleaning WITHOUT a prefix sweep: the row is removed by its EXACT
    // name, which carries `uniqueSuffix()` and therefore belongs to this run
    // alone. A worker that skipped the test owns nothing and deletes nothing —
    // which is exactly what stops the Mobile Chrome worker from reaching into
    // the Desktop Chrome worker's fixture. Idempotent on purpose: the serial
    // teardown may already have reclaimed the row.
    //
    // `afterEach`, not `afterAll` — the file's own docblock records the measured
    // reason (`afterAll` fires once per WORKER that touched the file, at an
    // arbitrary moment, which is what deleted another worker's in-flight
    // template and turned a green run red). This file has a single test, so the
    // two are equivalent in reach and only the per-test one is safe by
    // construction. The template itself is created through the UI, so its id is
    // not in a create response and the lookup is by exact name — see
    // `helpers/ownership.ts` for why that is the one place a name lookup survives.
    test.afterEach(async () => {
        if (ownedTemplateNames.size === 0) {
            return;
        }
        const api = await loginAdminApi();
        try {
            const body = await (await api.get('/api/admin/badge-templates')).json();
            for (const template of body.data ?? []) {
                if (!ownedTemplateNames.has(template.name)) {
                    continue;
                }
                const removed = await api.delete(`/api/admin/badge-templates/${template.id}`);
                // 204 = deleted, 404 = somebody else (the teardown) got there
                // first. Anything else is a real failure and must be loud.
                if (removed.status() !== 204 && removed.status() !== 404) {
                    throw new Error(`Deleting badge template ${template.id} failed with status ${removed.status()}`);
                }
            }
        } finally {
            await api.dispose();
        }
    });

    test('creates a template, exports PDF badges and verifies a token publicly', { tag: ['@feature:badge'] }, async ({ page }) => {
        // Setup: one accreditation with one approved application (portrait
        // uploaded, apply + allocate via API). The approved row carries the
        // relative verify URL `/verify/<token>`.
        const { accreditation, application } = await ensurePrimaryMandantApprovedApplication();
        const verifyToken = application.qr_url.split('/').pop();

        // Direct admin-URL load is the allowed route-guard exception: as a
        // guest, RequireAdmin redirects to /login (state.from) and the SPA
        // returns to /admin/badge-templates after the login.
        await page.goto('/admin/badge-templates');
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/badge-templates$/);

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { level: 1, name: 'Ausweis-Templates' })).toBeVisible();

        // Create a badge template via the UI: name + three layout fields
        // (name, category, date) and mark it as the mandant default.
        // When no templates exist yet, "Neu" appears in the header AND in the
        // empty-state panel CTA — the header one (first) is equivalent.
        await main.getByRole('button', { name: 'Neu', exact: true }).first().click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Neues Template' })).toBeVisible();

        await dialog.getByLabel('Name', { exact: true }).fill(TEMPLATE_NAME);
        await dialog.getByLabel('Standard-Template').check();
        // The editor starts with one default name row; the palette adds the
        // other two data fields.
        await dialog.getByRole('button', { name: 'Kategorie', exact: true }).click();
        await dialog.getByRole('button', { name: 'Datum', exact: true }).click();

        await dialog.getByRole('button', { name: 'Template erstellen' }).click();
        // The row is addressed by its EXACT name (`exact: true`), not by the
        // `E2E Ausweis` prefix — a regex prefix would also match
        // `E2E Badge Mobile …` and any other spec's template, which is the
        // StrictMode violation this file used to be one `afterAll` away from.
        const templateRow = main.getByRole('row', { name: new RegExp(TEMPLATE_NAME) });
        await expect(templateRow).toBeVisible();
        await expect(templateRow.getByText('Standard')).toBeVisible();
        await expect(templateRow.getByText('3 Felder')).toBeVisible();

        // Claim the row for this run's cleanup — by name, and the name is unique
        // per run, so the claim cannot pick up a row that is not ours. This also
        // doubles as a postcondition: a template that was created but is not in
        // the list would break the export below, and this says so here.
        ownedTemplateNames.add(TEMPLATE_NAME);
        {
            const api = await loginAdminApi();
            try {
                const body = await (await api.get('/api/admin/badge-templates')).json();
                let found = 0;
                for (const template of body.data ?? []) {
                    if (template.name === TEMPLATE_NAME) {
                        found += 1;
                    }
                }
                expect(found, `"${TEMPLATE_NAME}" is listed exactly once`).toBe(1);
            } finally {
                await api.dispose();
            }
        }

        // Navigate (SPA) to the approvals view and export the approved badges
        // as PDF from the accreditation's Ausweis-Export section.
        await page.getByRole('complementary').getByRole('link', { name: 'Freigaben', exact: true }).click();
        await expect(page).toHaveURL(/\/admin\/freigaben$/);
        await main.getByLabel('Akkreditierung', { exact: true }).selectOption(String(accreditation.id));

        // The export's own response is watched, not just the download event.
        //
        // A bare `page.waitForEvent('download')` inherits the TEST timeout, so
        // any UI error behind it (422 "No badge template.", a throttled 429, a
        // failed render) presents as a 120 s hang with no clue what went wrong.
        // MEASURED, that is exactly how this file's own concurrency bug stayed
        // undiagnosed: the 422's body never reached the report.
        //
        // So the response is captured explicitly and asserted. This is strictly
        // stronger than waiting for a download: a 200 that somehow produces no
        // download event would still be caught by the timeout below, and every
        // non-200 is reported with its body — the actual cause — instead of
        // timing out.
        const exportResponse = page.waitForResponse(
            (response) => response.url().includes('/badges/export') && response.request().method() === 'POST',
        );
        await main.getByRole('button', { name: 'PDF', exact: true }).click();
        const response = await exportResponse;
        if (response.status() !== 200) {
            const body = (await response.text()).slice(0, 300);
            throw new Error(
                `Badge export answered ${response.status()} (expected 200): ${body}. ` +
                    'The status line is the cause — do not re-run and hope.',
            );
        }
        expect(response.headers()['content-type']).toContain('application/pdf');

        // With a 200 in hand the download is a formality, and a bounded wait
        // keeps a regression here from eating the whole test budget again.
        const download = await page.waitForEvent('download', { timeout: 15000 });
        expect(download.suggestedFilename()).toBe(`badges-${accreditation.id}.pdf`);
        // No `saveAs` — the fixture keeps the download in memory; cancelling
        // releases it cleanly instead of letting the harness drop it.
        await download.cancel();

        // Public verify page: opening the approved application's qr_url (guest
        // page.goto is the allowed route exception) shows the status, identity
        // and the streamed portrait.
        await page.goto(`/verify/${verifyToken}?token=${verifyToken}`);
        const verifyMain = page.getByRole('main');
        await expect(verifyMain.getByText('Akkreditiert', { exact: true })).toBeVisible();
        await expect(verifyMain.getByText('E2E Badge Inhaber')).toBeVisible();
        await expect(verifyMain.getByRole('img', { name: 'Foto' })).toBeVisible();

        // A tampered/unknown token shows the invalid-code state.
        await verifyMain.getByLabel('Code', { exact: true }).fill('ungueltig');
        await verifyMain.getByRole('button', { name: 'Prüfen', exact: true }).click();
        await expect(verifyMain.getByText('Ungültiger Code.')).toBeVisible();
    });
});
