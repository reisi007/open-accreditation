import { expect, test } from '@playwright/test';
import { reclaimOwnedRows, resetOwnedRows } from './helpers/ownership';
// Per-test ownership (tests/e2e/helpers/ownership.ts), at FILE scope for the
// reason every spec states: a describe-scoped hook would have covered only one
// describe, which is the "the teardown exists somewhere in this file" illusion
// the namespace-isolation gate exists to end. This file creates NO rows of its
// own — the requeue it drives removes the (stubbed) dead letter and the ledger
// therefore stays empty, so the hook pays nothing.
test.beforeEach(async () => {
    resetOwnedRows();
});
test.afterEach(async () => {
    await reclaimOwnedRows();
});

import {
    cutException,
    deadLetter,
    DEAD_LETTER_LIST_PATTERN,
    DEAD_LETTER_REQUEUE_PATTERN,
    FIRST_RECIPIENT,
    REAL_REQUEUE_MESSAGE,
    realDeadLetterList,
    realRequeueStatusForUnknownLetter,
    REFUSED_RECIPIENT,
    REQUEUED_RECIPIENT,
    SECOND_RECIPIENT,
    stubDeadLetterList,
    UNKNOWN_DEAD_LETTER_ID,
} from './helpers/failed-mails';

/**
 * Admin dead-letter queue (Position 45, Strom B).
 *
 * ## What is real here and what is answered at the API boundary
 *
 * Read `helpers/failed-mails.ts` first: there is no route that CREATES a
 * `failed_jobs` row (only the worker writes one, and no worker runs in the E2E
 * stack), so the LIST is fulfilled with the measured `FailedMailResource`
 * shape while the `POST …/requeue` goes to the real backend. The two tests that
 * need no fixture at all — the real empty state and the real 404 — hit the
 * backend unmodified and are what anchor the rest.
 */
test.describe('Admin: Tote Briefe (DLQ)', () => {
    // UI-heavy spec: Desktop Chrome only, for the reason `approvals.spec.ts` and
    // `admin-users.spec.ts` state — the shared per-IP login throttle budget must
    // stay available for the parallel feature specs.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('an empty queue is its own state, not a load failure', { tag: ['@smoke', '@feature:admin:dlq'] }, async ({ page }) => {
        // No stub at all: the REAL endpoint, on a stack where no worker runs and
        // so really has no dead letters. That is what makes this a measurement —
        // a mocked `{data: []}` would pass identically if the page rendered the
        // load error and the empty state with the same text.
        const real = await realDeadLetterList();
        expect(real.status, 'the real DLQ list must be readable by a super_admin').toBe(200);
        expect(Array.isArray(real.body.data), 'the DLQ list is a {data: [...]} envelope').toBe(true);
        expect(real.body.data, 'this stack has no dead letters, so the empty state is reachable').toHaveLength(0);

        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        await page.getByRole('complementary').getByRole('link', { name: 'Tote Briefe' }).click();
        await expect(page).toHaveURL(/\/admin\/tote-briefe$/);

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { level: 1, name: 'Tote Briefe' })).toBeVisible();
        await expect(main.getByRole('heading', { name: 'Keine toten Briefe.' })).toBeVisible();
        await expect(main.getByText('0 Briefe', { exact: true })).toBeVisible();

        // The two states must not be confusable: a load failure is `role="alert"`
        // and this capture must hold none.
        await expect(main.getByRole('alert')).toHaveCount(0);

        // The list endpoint is not paginated, and the page has to say so instead
        // of letting a long list read as "everything there is"
        // (`features/mail-delivery.md §8`).
        await expect(main.getByText(/Die Liste wird nicht seitenweise geladen/)).toBeVisible();
    });

    test('requeues a dead letter and reports what the SERVER did', { tag: ['@smoke', '@feature:admin:dlq'] }, async ({ page }) => {
        // The board asks for `@smoke` on the requeue path, and this is where that
        // lands: the POST goes to the REAL backend and the UI is measured on the
        // REAL 404 it answers. The "happy path" cannot be a smoke test here — a
        // requeue that really succeeds needs a real `failed_jobs` row, and no
        // route creates one (see `helpers/failed-mails.ts`); the case below, where
        // the request reaches the backend and comes back refused, is the part of
        // the action this environment can measure end to end.
        //
        // The backend's own answer for a letter that does not exist, measured
        // before the test relies on it: this is what a foreign letter gets too.
        expect(
            await realRequeueStatusForUnknownLetter(),
            'a requeue of an unknown letter must be refused with 404',
        ).toBe(404);

        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        // The row the list shows carries the id the REAL requeue will address —
        // one that cannot exist, so the backend really answers 404 and the UI is
        // measured on what it does with a refusal.
        const refused = deadLetter({ id: UNKNOWN_DEAD_LETTER_ID, exception: cutException() });
        await stubDeadLetterList(page, [refused]);

        await page.getByRole('complementary').getByRole('link', { name: 'Tote Briefe' }).click();
        await expect(page).toHaveURL(/\/admin\/tote-briefe$/);

        const main = page.getByRole('main');
        const row = main.getByRole('row', { name: new RegExp(REFUSED_RECIPIENT) });
        await expect(row).toBeVisible();
        await expect(main.getByText('1 Brief', { exact: true })).toBeVisible();

        // A server-cut exception is LABELLED as cut. A trace shown as if it were
        // whole is the same defect class as the resend message this stream fixed.
        await expect(row.getByText('gekürzt auf 500 Zeichen')).toBeVisible();

        // Confirm first: a requeue is a human, logged decision.
        await row.getByRole('button', { name: 'Erneut einreihen' }).click();
        const dialog = page.getByRole('dialog');
        await expect(dialog.getByRole('heading', { name: 'Brief erneut einreihen' })).toBeVisible();
        await expect(dialog.getByText(REFUSED_RECIPIENT, { exact: false })).toBeVisible();

        await dialog.getByRole('button', { name: 'Erneut einreihen' }).click();

        // The REAL backend refused it (404) and the UI has to SAY so — an error
        // that renders as nothing would leave the admin believing the letter is
        // back on the queue.
        await expect(dialog.getByRole('alert')).toContainText('existiert nicht (mehr)');
        await expect(dialog.getByRole('alert')).toContainText('404');
        // …and next to its row, not only in a dialog that is easy to lose.
        await expect(row.getByRole('alert')).toContainText('existiert nicht (mehr)');
        // No success message anywhere: a refusal must never read as a requeue.
        await expect(main.getByText('Zustellauftrag angenommen.')).toHaveCount(0);
    });

    test('a server success is reported in the SERVER words, and the row leaves the list', { tag: ['@feature:admin:dlq'] }, async ({ page }) => {
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        const happy = deadLetter({ id: 4242, recipient: REQUEUED_RECIPIENT });

        // The list serves one row and then, after the requeue, none — so the
        // removal asserted below is the page's own revalidation, not a hidden
        // row. The requeue itself has to be stubbed here (a 200 with a body of
        // its own needs a real row to be legal, and none exists on this stack) —
        // which is why the OTHER requeue case lets the request reach the real
        // backend instead.
        //
        // Two patterns, not one: see `DEAD_LETTER_LIST_PATTERN`.
        let requeued = false;
        await page.route(DEAD_LETTER_LIST_PATTERN, (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: requeued ? [] : [happy] }),
            }),
        );
        await page.route(DEAD_LETTER_REQUEUE_PATTERN, (route) => {
            requeued = true;
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ message: REAL_REQUEUE_MESSAGE }),
            });
        });

        await page.getByRole('complementary').getByRole('link', { name: 'Tote Briefe' }).click();
        const main = page.getByRole('main');
        const row = main.getByRole('row', { name: new RegExp(REQUEUED_RECIPIENT) });
        await expect(row).toBeVisible();

        await row.getByRole('button', { name: 'Erneut einreihen' }).click();
        const dialog = page.getByRole('dialog');
        await dialog.getByRole('button', { name: 'Erneut einreihen' }).click();

        const status = main.getByRole('status');
        await expect(status).toContainText(REAL_REQUEUE_MESSAGE);
        // The dialog closes on success (the row is gone; there is nothing left to
        // confirm) and the list is revalidated.
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(row).toHaveCount(0);
        await expect(main.getByRole('heading', { name: 'Keine toten Briefe.' })).toBeVisible();
    });

    test('the filter narrows the list and says so when nothing matches', { tag: ['@feature:admin:dlq'] }, async ({ page }) => {
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        await stubDeadLetterList(page, [
            deadLetter({ id: 1, recipient: FIRST_RECIPIENT, mailable: 'App\\Mail\\PassMail' }),
            deadLetter({ id: 2, recipient: SECOND_RECIPIENT, mailable: 'App\\Mail\\ActivationMail' }),
        ]);

        await page.getByRole('complementary').getByRole('link', { name: 'Tote Briefe' }).click();
        const main = page.getByRole('main');
        await expect(main.getByRole('row', { name: new RegExp(FIRST_RECIPIENT) })).toBeVisible();
        await expect(main.getByRole('row', { name: new RegExp(SECOND_RECIPIENT) })).toBeVisible();
        await expect(main.getByText('2 Briefe', { exact: true })).toBeVisible();

        await main.getByLabel('Suche', { exact: true }).fill('ActivationMail');
        await expect(main.getByRole('row', { name: new RegExp(SECOND_RECIPIENT) })).toBeVisible();
        await expect(main.getByRole('row', { name: new RegExp(FIRST_RECIPIENT) })).toHaveCount(0);
        await expect(main.getByText('1 Brief', { exact: true })).toBeVisible();

        // A filter that matches nothing is its OWN state, distinct from the empty
        // queue — "nothing matched" is not "nothing died".
        await main.getByLabel('Suche', { exact: true }).fill('gibtesnicht');
        await expect(main.getByRole('heading', { name: 'Keine Briefe für diese Filter.' })).toBeVisible();
        await expect(main.getByRole('heading', { name: 'Keine toten Briefe.' })).toHaveCount(0);
    });

    test('a list that cannot be loaded is an error, never an empty queue', { tag: ['@feature:admin:dlq'] }, async ({ page }) => {
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        // What a role without `mails.dlq.manage` really gets from the gate.
        await page.route(DEAD_LETTER_LIST_PATTERN, (route) =>
            route.fulfill({
                status: 403,
                contentType: 'application/json',
                body: JSON.stringify({ message: 'This action is unauthorized.' }),
            }),
        );

        await page.getByRole('complementary').getByRole('link', { name: 'Tote Briefe' }).click();
        const main = page.getByRole('main');
        await expect(main.getByRole('alert')).toContainText('Keine Berechtigung für die Liste der toten Briefe.');
        // The empty state must NOT be on screen: an outage that reads as "all
        // letters were delivered" is the failure this stream exists to prevent.
        await expect(main.getByRole('heading', { name: 'Keine toten Briefe.' })).toHaveCount(0);
    });
});
