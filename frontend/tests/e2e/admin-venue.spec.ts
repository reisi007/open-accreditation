import { expect, test } from '@playwright/test';
import { uniqueSuffix } from './helpers/admin-data';
import { findCreatedRowId } from './helpers/created-row';
import { reclaimOwnedRows, rememberOwnedRow, resetOwnedRows } from './helpers/ownership';
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
 * ## The ledger is NOT empty in this file any more (Position 22)
 *
 * It was, and the paragraph that said so was a record of a known gap rather than
 * a defence of it — MEASURED, adding this file to `UI_CREATE_SITES` in
 * `namespace-isolation.spec.ts` turned `THE GUARD: a spec that creates through
 * the UI registers what it created` RED, naming this file and `Team speichern`.
 * Position 22 closed it.
 *
 * Both tests create rows through FORMS, and a create form answers no id, so each
 * create is followed immediately by a lookup under its EXACT, worker-stamped
 * name (`helpers/created-row.ts` — the shared form of what
 * `admin-mandant.spec.ts` used to carry privately). Four rows, four
 * registrations:
 *
 * | test    | row      | key                | registered |
 * |---------|----------|--------------------|------------|
 * | 1       | venue    | `name` as typed    | after the create submit, BEFORE the rename |
 * | 2       | venue    | `name` as typed    | after the inline create in the team form |
 * | 2       | team     | `name`             | after `Team speichern`, with the mandant as `parentId` |
 *
 * ## What the UI delete at the end of test 1 now means
 *
 * It used to be this test's ONLY cleanup, which made it load-bearing: a failure
 * anywhere between the create and that button left the venue behind, and the
 * serial `globalTeardown` name sweep was the net that eventually took it — one
 * row per run, for as long as it took a human to look. Now the ledger is the
 * first net and the button is the second. The teardown's DELETE of a row the UI
 * already removed answers 404, which `helpers/ownership.ts` classifies as
 * "the row is gone" and counts as reclaimed — so the double cleanup is the
 * measured shape, not a contradiction.
 *
 * ## What the ORDER is
 *
 * `E2E_OWNED_TEARDOWN` puts `teams` before `venues`, and `teams.venue_id` is
 * `restrictOnDelete`: deleting the venue while the team still points at it is
 * refused with 409 (F1's 53 refused venue deletes were exactly that). The plan
 * order, not the registration order, is what satisfies it, and
 * `namespace-isolation.spec.ts` walks `E2E_OWNED_FK_EDGES` to prove it.
 */

/**
 * Venue master data (W12).
 *
 * The mandant's venues are shared reference data: teams and events point at
 * them by id, a referenced venue is DEACTIVATED rather than deleted, and the
 * create affordance has to live where the admin needs it — inside the team form.
 * That last point is the headline of the second test.
 *
 * Not tagged `@smoke` on purpose: it drives the newest admin surface and was
 * written against the API contract, so promoting it into the push gate before
 * it has been executed against a live backend would put an unverified test in
 * front of every change.
 *
 * NOTE on style: this directory is linted with the PLAIN-JS parser but still
 * type-checked by the strict `tsc -b`, so a helper may NOT take a parameter (an
 * annotation is an ESLint parse error, an un-annotated one an implicit `any`).
 * That is why the login block is inlined in both tests — the same reason
 * `admin-event.spec.ts` and `admin-category.spec.ts` inline theirs.
 */
test.describe('Admin: Spielorte (W12)', () => {

    // UI-heavy spec: run once (Desktop Chrome) to avoid throttled duplicate
    // login calls and redundant DOM interaction on the mobile project.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('create, rename, deactivate, reactivate and delete a venue', { tag: ['@feature:admin:venue'] }, async ({ page }) => {
        const suffix = uniqueSuffix();
        const name = `E2E Spielort ${suffix}`;
        const renamed = `${name} umbenannt`;

        // Initial guest load is the only allowed page.goto.
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants$/);

        // The nav entry sits with the other reference-data pages.
        await page.getByRole('complementary').getByRole('link', { name: 'Spielorte' }).click();
        await expect(page).toHaveURL(/\/admin\/venues$/);
        const adminMain = page.getByRole('main');
        await expect(adminMain.getByRole('heading', { level: 1, name: 'Spielorte' })).toBeVisible();

        // Create.
        await adminMain.getByRole('button', { name: 'Neu' }).first().click();
        await adminMain.getByLabel('Name', { exact: true }).fill(name);
        await adminMain.getByRole('button', { name: 'Spielort erstellen' }).click();
        const venueRow = adminMain.getByRole('row', { name: new RegExp(name) });
        await expect(venueRow).toBeVisible();
        // Registered before the rename two steps down, and under the name AS
        // TYPED: the ledger addresses rows by id, so the rename cannot make this
        // one unaddressable, and a failure in the rename, the deactivate/reactivate
        // cycle or the delete leaves the venue owned instead of to the serial name
        // sweep (Position 22).
        rememberOwnedRow('venues', await findCreatedRowId('/api/admin/venues', 'name', name, 'venues'));
        // Unreferenced ⇒ deleteable.
        await expect(venueRow.getByRole('button', { name: 'Löschen' })).toBeEnabled();

        // Rename.
        await venueRow.getByRole('button', { name: 'Umbenennen' }).click();
        await adminMain.getByLabel('Name', { exact: true }).fill(renamed);
        await adminMain.getByRole('button', { name: 'Speichern' }).click();
        const renamedRow = adminMain.getByRole('row', { name: new RegExp(renamed) });
        await expect(renamedRow).toBeVisible();

        // Deactivate → reactivate. A deactivated name keeps its row (deactivation
        // is the reversible action; only unreferenced rows may be deleted).
        await renamedRow.getByRole('button', { name: 'Deaktivieren' }).click();
        await expect(renamedRow.getByText('Inaktiv', { exact: true })).toBeVisible();
        await renamedRow.getByRole('button', { name: 'Reaktivieren' }).click();
        await expect(renamedRow.getByText('Aktiv', { exact: true })).toBeVisible();

        // Delete (confirm dialog).
        page.on('dialog', (dialog) => void dialog.accept());
        await renamedRow.getByRole('button', { name: 'Löschen' }).click();
        await expect(adminMain.getByRole('row', { name: new RegExp(renamed) })).toHaveCount(0);
    });

    test('creates a venue inline from the team form and refuses to re-create a deactivated name', { tag: ['@feature:admin:venue'] }, async ({ page }) => {
        const suffix = uniqueSuffix();
        const venueName = `E2E Heimstadion ${suffix}`;
        const teamName = `E2E Team ${suffix}`;
        const teamSlug = `e2e-team-${suffix}`;

        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/login$/);

        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants$/);

        // Teams are managed on the mandant detail page; the bootstrap mandant is
        // the seeded "Hauptseite".
        await page.getByRole('main').getByRole('link', { name: 'Hauptseite' }).click();
        await expect(page).toHaveURL(/\/admin\/mandants\/\d+$/);
        const adminMain = page.getByRole('main');
        // The mandant id, read out of the URL the navigation landed on.
        // `E2E_OWNED_TEARDOWN` addresses a `teams` row through the mandant-scoped
        // route `/api/admin/mandants/{parentId}/teams`, so this third argument is
        // not optional bookkeeping — without it the teardown would DELETE
        // `/api/admin/mandants/0/teams/{id}`, which answers the "row is gone"
        // 404, and the team would survive a cleanup that reported success.
        const mandantId = Number(new URL(page.url()).pathname.split('/').pop());
        await adminMain.getByRole('button', { name: 'Team hinzufügen' }).click();

        // The mandant may have no venue at all yet — that is exactly the case a
        // select-only dropdown could not solve, so the create affordance has to
        // be right here in the form.
        const venueField = adminMain.getByRole('combobox', { name: 'Heimstätte' });
        await venueField.click();
        await venueField.fill(venueName);
        await adminMain.getByRole('option', { name: `${venueName} neu anlegen` }).click();
        // The inline create selects the new venue; the field shows its name.
        await expect(venueField).toHaveValue(venueName);
        // Registered HERE, at the first moment the venue demonstrably exists. This
        // test deleted NOTHING before Position 22, so its `E2E Heimstadion …` row
        // (+1 venue per run) was reachable only by the serial name sweep — and a
        // sweep cannot run when the run was killed, which is the failure the
        // ledger exists for.
        rememberOwnedRow('venues', await findCreatedRowId('/api/admin/venues', 'name', venueName, 'venues'));

        await adminMain.getByLabel('Team-Name', { exact: true }).fill(teamName);
        await adminMain.getByLabel('Team-Slug', { exact: true }).fill(teamSlug);
        await adminMain.getByRole('button', { name: 'Team speichern' }).click();
        await expect(adminMain.getByText(teamName, { exact: true })).toBeVisible();
        // …and the team, with its mandant, because its DELETE route is
        // mandant-scoped. Without the third argument the teardown would address
        // mandant 0 and count the answer as reclaimed.
        rememberOwnedRow(
            'teams',
            await findCreatedRowId(`/api/admin/mandants/${mandantId}/teams`, 'name', teamName, 'teams'),
            mandantId,
        );

        // The venue is now referenced ⇒ deactivate is the primary action and
        // delete is not offered.
        await page.getByRole('complementary').getByRole('link', { name: 'Spielorte' }).click();
        const venueRow = page.getByRole('main').getByRole('row', { name: new RegExp(venueName) });
        await expect(venueRow).toBeVisible();
        await expect(venueRow.getByRole('button', { name: 'Deaktivieren' })).toBeEnabled();
        await expect(venueRow.getByRole('button', { name: 'Löschen' })).toHaveCount(0);

        // A deactivated name is NOT re-creatable. Deactivate it via the page,
        // then check the combobox in the event form: the row shows greyed with
        // a reactivate button and NO "create" entry — and reactivating from
        // inside the form needs no detour to the venue page.
        await venueRow.getByRole('button', { name: 'Deaktivieren' }).click();
        await expect(venueRow.getByText('Inaktiv', { exact: true })).toBeVisible();

        await page.getByRole('complementary').getByRole('link', { name: 'Events' }).click();
        // `.first()` for the same reason as the venues page above: with an EMPTY
        // event list the page renders a second "Neu" in its empty state, which
        // would make this locator a strict-mode violation.
        await page.getByRole('main').getByRole('button', { name: 'Neu' }).first().click();
        await page.getByRole('main').getByLabel('Team', { exact: true }).selectOption({ label: teamName });
        const eventVenueField = page.getByRole('main').getByRole('combobox', { name: 'Spielort' });
        await eventVenueField.click();
        await eventVenueField.fill(venueName);
        await expect(page.getByRole('main').getByRole('option', { name: `${venueName} neu anlegen` })).toHaveCount(0);
        await expect(page.getByRole('main').getByRole('option', { name: new RegExp(venueName) })).toHaveAttribute(
            'aria-disabled',
            'true',
        );
        await page.getByRole('main').getByRole('button', { name: `${venueName} reaktivieren` }).click();
        await expect(eventVenueField).toHaveValue(venueName);
    });
});
