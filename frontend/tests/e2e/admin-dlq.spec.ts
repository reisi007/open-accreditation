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
 * need no fixture at all — the real list and the real 404 — hit the backend
 * unmodified and are what anchor the rest.
 *
 * ## The rule this file follows: no assertion about the data set
 *
 * Every expectation is derived either from what the backend just answered or
 * from a stub this file registers itself. Nothing asserts "the stack holds no
 * dead letters" — that is a claim about accumulated data, it changes without a
 * line of product code changing, and it is exactly how a `@smoke` test ends up
 * red for an unrelated reason (the `an empty queue …` case documents its own
 * measurement of that). The empty state is therefore served on purpose instead
 * of hoped for; `ui-review.config.ts` solves the same problem with
 * `emptyMock`, and the two must not drift apart.
 */
test.describe('Admin: Tote Briefe (DLQ)', () => {
    // UI-heavy spec: Desktop Chrome only, for the reason `approvals.spec.ts` and
    // `admin-users.spec.ts` state — the shared per-IP login throttle budget must
    // stay available for the parallel feature specs.
    test.beforeEach(async ({}, testInfo) => {
        test.skip(testInfo.project.name !== 'Desktop Chrome');
    });

    test('an empty queue is its own state, not a load failure', { tag: ['@smoke', '@feature:admin:dlq'] }, async ({ page }) => {
        // This test used to assert `expect(real.body.data).toHaveLength(0)` and
        // to drive the page with the real, unstubbed answer. Both are gone, and
        // the reason is the difference between a CLAIM ABOUT THE DATA SET and a
        // claim about the code under test: "this stack has no dead letters" is
        // the former. One `failed_jobs` row anywhere in the E2E stack — a job
        // that exhausted `$tries` against a relay that was down while the stack
        // was up — turns a `@smoke` test red while the page renders perfectly.
        // MEASURED (2026-10-03, this host): with ONE real `failed_jobs` row
        // inserted, the previous version failed at that assertion and the page
        // it had just loaded showed the right thing.
        //
        // What replaces it keeps both halves and drops only the assumption:
        //   (1) the REAL endpoint is measured — status and envelope — and the
        //       page is driven by that real answer, with every expectation
        //       DERIVED from the length the server reported;
        //   (2) the empty state is then served explicitly (the same
        //       `emptyMock` the screenshot harness uses), so it is measured on
        //       any stack, empty or not.
        const real = await realDeadLetterList();
        expect(real.status, 'the real DLQ list must be readable by a super_admin').toBe(200);
        expect(Array.isArray(real.body.data), 'the DLQ list is a {data: [...]} envelope').toBe(true);
        // MEASURED 2026-10-06: the paginated endpoint answers a `meta` window
        // with `per_page` 50 for an unparameterized request, and an EMPTY queue
        // answers `last_page: 1` (not 0 — `ceil(0/50)` is 0 and a page control
        // with zero pages has no state).
        expect(real.body.meta, 'the DLQ list is a {data, meta} envelope').toMatchObject({
            page: 1,
            per_page: 50,
            last_page: expect.any(Number),
        });
        // Unreachable with a failing expectation above (Playwright's `expect`
        // throws); `[]` exists only so the length below is typed. No type
        // annotation here: this directory is linted as plain ES2020 (espree) and
        // would reject it — `Array.isArray` narrows the value anyway.
        const realData = Array.isArray(real.body.data) ? real.body.data : [];
        // `meta.total`, not `data.length`: the endpoint is paginated, so the rows
        // on screen are ONE WINDOW and the length would understate the queue.
        // `total` is a documented upper bound (see `FailedMailController`), which
        // is why this test compares against the number the SERVER reported.
        const realCount = real.body.meta?.total ?? realData.length;

        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        // (1) The REAL answer, unstubbed. A page that hard-codes "0 Briefe"
        // fails here as soon as the data set is not empty; one that draws the
        // empty card over a non-empty answer fails with it.
        await page.getByRole('complementary').getByRole('link', { name: 'Tote Briefe' }).click();
        await expect(page).toHaveURL(/\/admin\/tote-briefe$/);

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { level: 1, name: 'Tote Briefe' })).toBeVisible();
        await expect(
            main.getByText(new RegExp(`${realCount} Briefe insgesamt`)),
            'the page must report the number the SERVER sent as the queue total',
        ).toBeVisible();
        await expect(
            main.getByRole('heading', { name: 'Keine toten Briefe.' }),
            'the empty state belongs to an empty ANSWER, not to an empty stack',
        ).toHaveCount(realCount === 0 ? 1 : 0);

        // The two states must not be confusable: a load failure is `role="alert"`
        // and this capture must hold none — here on the REAL answer, too.
        await expect(main.getByRole('alert')).toHaveCount(0);

        // The count and the TOTAL are always shown together, so a page that holds
        // part of the queue can never read as the whole of it
        // (`features/mail-delivery.md §8`) — in every state, empty or filled.
        await expect(main.getByText(/insgesamt/)).toBeVisible();

        // (2) The empty state, served explicitly. Deterministic by construction:
        // nothing about this half depends on what the stack happens to hold.
        await stubDeadLetterList(page, []);
        await page.reload();
        await expect(main.getByRole('heading', { name: 'Keine toten Briefe.' })).toBeVisible();
        await expect(main.getByText(/0 Briefe insgesamt/)).toBeVisible();
        await expect(main.getByRole('alert')).toHaveCount(0);
        // One page of nothing: no counter, because there is nowhere to go.
        await expect(main.getByText(/^Seite \d+ von \d+$/)).toHaveCount(0);
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
        await expect(main.getByText(/1 Brief insgesamt/)).toBeVisible();

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
        await expect(main.getByText(/2 Briefe insgesamt/)).toBeVisible();

        await main.getByLabel('Suche', { exact: true }).fill('ActivationMail');
        await expect(main.getByRole('row', { name: new RegExp(SECOND_RECIPIENT) })).toBeVisible();
        await expect(main.getByRole('row', { name: new RegExp(FIRST_RECIPIENT) })).toHaveCount(0);
        // The filter counts the PAGE; the total beside it stays the queue's. This
        // pairing is what stops "1 Brief" from reading as a claim about the whole
        // queue — the filter cannot see other pages, so 1 of 2 is the honest pair.
        await expect(main.getByText('1 Brief · 2 Briefe insgesamt')).toBeVisible();

        // A filter that matches nothing is its OWN state, distinct from the empty
        // queue — "nothing matched" is not "nothing died".
        await main.getByLabel('Suche', { exact: true }).fill('gibtesnicht');
        await expect(main.getByRole('heading', { name: 'Keine Briefe für diese Filter.' })).toBeVisible();
        await expect(main.getByRole('heading', { name: 'Keine toten Briefe.' })).toHaveCount(0);
    });

    test('the page counter names the WINDOW, and "next" serves the next window', { tag: ['@feature:admin:dlq'] }, async ({ page }) => {
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        // THREE pages of TWO, so every page boundary is data rather than a
        // number this test typed: page 1 has rows 1-2, page 2 has 3-4, page 3
        // has 5-6. `stubDeadLetterList` slices by the requested page/per_page.
        const rows = [
            deadLetter({ id: 1, recipient: 'e2e-dlq-p1-a@example.test' }),
            deadLetter({ id: 2, recipient: 'e2e-dlq-p1-b@example.test' }),
            deadLetter({ id: 3, recipient: 'e2e-dlq-p2-a@example.test' }),
            deadLetter({ id: 4, recipient: 'e2e-dlq-p2-b@example.test' }),
            deadLetter({ id: 5, recipient: 'e2e-dlq-p3-a@example.test' }),
            deadLetter({ id: 6, recipient: 'e2e-dlq-p3-b@example.test' }),
        ];
        await stubDeadLetterList(page, rows, { perPage: 2 });

        await page.getByRole('complementary').getByRole('link', { name: 'Tote Briefe' }).click();
        const main = page.getByRole('main');

        // Page 1: the counter, the total, and only page 1's rows.
        await expect(main.getByText('Seite 1 von 3')).toBeVisible();
        await expect(main.getByText(/6 Briefe insgesamt/)).toBeVisible();
        await expect(main.getByRole('row', { name: new RegExp('e2e-dlq-p1-a') })).toBeVisible();
        await expect(main.getByRole('row', { name: new RegExp('e2e-dlq-p2-a') })).toHaveCount(0);

        // "Zurück" is dead on page 1 — a control that would issue a page 0.
        await expect(main.getByRole('button', { name: 'Zurück' })).toBeDisabled();

        // "Weiter" really asks for page 2, and page 2's rows replace page 1's.
        await main.getByRole('button', { name: 'Weiter' }).click();
        await expect(main.getByText('Seite 2 von 3')).toBeVisible();
        await expect(main.getByRole('row', { name: new RegExp('e2e-dlq-p2-a') })).toBeVisible();
        await expect(main.getByRole('row', { name: new RegExp('e2e-dlq-p1-a') })).toHaveCount(0);

        // The last page: "Weiter" is dead, and the table holds the remainder.
        await main.getByRole('button', { name: 'Weiter' }).click();
        await expect(main.getByText('Seite 3 von 3')).toBeVisible();
        await expect(main.getByRole('row', { name: new RegExp('e2e-dlq-p3-a') })).toBeVisible();
        await expect(main.getByRole('row', { name: new RegExp('e2e-dlq-p3-b') })).toBeVisible();
        await expect(main.getByRole('button', { name: 'Weiter' })).toBeDisabled();
        await expect(main.getByRole('button', { name: 'Zurück' })).toBeEnabled();
    });

    test('a filter returns to page 1, because its result has no page 4', { tag: ['@feature:admin:dlq'] }, async ({ page }) => {
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        const rows = Array.from({ length: 6 }, (_, i) =>
            deadLetter({ id: i + 1, recipient: `e2e-dlq-filter-${i + 1}@example.test` }),
        );
        await stubDeadLetterList(page, rows, { perPage: 2 });

        await page.getByRole('complementary').getByRole('link', { name: 'Tote Briefe' }).click();
        const main = page.getByRole('main');
        await expect(main.getByText('Seite 1 von 3')).toBeVisible();

        await main.getByRole('button', { name: 'Weiter' }).click();
        await expect(main.getByText('Seite 2 von 3')).toBeVisible();

        // The filter only ever sees the CURRENT page, so narrowing it while on
        // page 2 must re-request page 1 — staying on page 2 would render an
        // empty table as if the queue held nothing.
        await main.getByLabel('Suche', { exact: true }).fill('filter-1');
        await expect(main.getByText('Seite 1 von 3')).toBeVisible();
        await expect(main.getByRole('row', { name: new RegExp('e2e-dlq-filter-1@') })).toBeVisible();
    });

    test('a page that can no longer exist falls back to the last one', { tag: ['@feature:admin:dlq'] }, async ({ page }) => {
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        // The queue SHRINKS between the page being shown and the next request:
        // 4 letters across 2 pages, then 2 letters across 1 page. Requesting page 2
        // must land on page 1 — never on an empty table under a "page 2 of 2"
        // heading that no longer exists.
        let total = 4;
        await page.route(DEAD_LETTER_LIST_PATTERN, (route) => {
            if (route.request().method() !== 'GET') return route.continue();
            const url = new URL(route.request().url());
            const requested = Number(url.searchParams.get('page') ?? '1');
            // The page asks for 50; this fixture wants windows of 2, so the
            // served window is capped (see `stubDeadLetterList` for why the cap
            // has to be here rather than in the shared helper).
            const size = Math.min(Number(url.searchParams.get('per_page') ?? '50'), 2);
            const rows = total === 4
                ? [
                      deadLetter({ id: 1, recipient: 'e2e-dlq-shrink-1@example.test' }),
                      deadLetter({ id: 2, recipient: 'e2e-dlq-shrink-2@example.test' }),
                  ]
                : [deadLetter({ id: 1, recipient: 'e2e-dlq-shrink-1@example.test' })];
            const slice = rows.slice((requested - 1) * size, requested * size);
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: slice,
                    meta: {
                        page: requested,
                        per_page: size,
                        total,
                        last_page: Math.max(1, Math.ceil(total / size)),
                    },
                }),
            });
        });
        await page.route(DEAD_LETTER_REQUEUE_PATTERN, (route) => route.continue());

        await page.getByRole('complementary').getByRole('link', { name: 'Tote Briefe' }).click();
        const main = page.getByRole('main');
        await expect(main.getByText('Seite 1 von 2')).toBeVisible();

        // A colleague requeued two letters: the queue is now one page. The
        // requeue happens AFTER page 1 is on screen, so "Weiter" is pressed
        // against a page state the server no longer agrees with — the exact
        // window in which `failedMailWindow()`'s clamp earns its place.
        total = 1;
        await main.getByRole('button', { name: 'Weiter' }).click();

        // The page-2 request came back EMPTY. The render-time clamp corrects the page
        // state, and the admin is put back on a window that has rows — that is the
        // half of the contract that can be asserted without depending on WHEN
        // SWR revalidates the window it lands on.
        await expect(main.getByRole('row', { name: new RegExp('e2e-dlq-shrink-1') })).toBeVisible();
        // The empty-state card is the lie this guards against: a queue that holds
        // a letter must never render "Keine toten Briefe."
        await expect(main.getByRole('heading', { name: 'Keine toten Briefe.' })).toHaveCount(0);
        // Still no error either — the endpoint answered, it just answered empty.
        await expect(main.getByRole('alert')).toHaveCount(0);
    });

    test('an unreachable page never says every letter was delivered', { tag: ['@feature:admin:dlq'] }, async ({ page }) => {
        await page.goto('/');
        await page.getByRole('banner').getByRole('link', { name: 'Anmelden' }).click();
        const loginMain = page.getByRole('main');
        await loginMain.getByLabel('E-Mail', { exact: true }).fill('admin@example.com');
        await loginMain.getByLabel('Passwort', { exact: true }).fill('admin');
        await loginMain.getByRole('button', { name: 'Anmelden' }).click();
        await expect(page).toHaveURL(/\/admin\//);

        // MEASURED 2026-10-06 (verification round 71, real controller, previous
        // ceiling of 20 windows): 1050 letters, `per_page=50` → `meta.last_page: 21`,
        // and `page=21` answered with **0 rows**. The page rendered
        // "Alle Briefe wurden zugestellt." over a queue holding 1050 letters.
        // (Ceiling since 2026-10-06: 100 windows — the same 1050 letters now
        // report `last_page: 21` AND serve it.)
        //
        // Why the envelope is served here rather than measured from the running
        // backend: producing 1050 real dead letters needs a worker (only a worker
        // writes `failed_jobs` — `QUEUE_CONNECTION=sync` in this stack cannot),
        // and the DLQ spec's own rule forbids asserting about the data set instead.
        // So this is the ANSWER that was measured, not the answer this stack
        // happens to hold — and it is served with the same shape the real
        // endpoint produces.
        //
        // `last_page: 21` with an empty page 21 is therefore served DELIBERATELY
        // although the raised server would now FILL page 21 for these 1050 letters:
        // the client state under test — no rows while `total > 0` — is still
        // reachable after the ceiling raise, because a window
        // filled with non-mail rows counts into `total` without yielding a letter
        // (`total` is a documented upper bound), as does a short last page landing
        // exactly on window 100. The client cannot tell any of that apart
        // from the ceiling, so it must not say "everything was delivered" in
        // either case. A fixture that only served the fixed answer would delete the
        // state instead of testing it.
        await page.route(DEAD_LETTER_LIST_PATTERN, (route) => {
            if (route.request().method() !== 'GET') return route.continue();
            const url = new URL(route.request().url());
            const requested = Number(url.searchParams.get('page') ?? '1');
            // Pages 1–20 are served full; page 21 is the one `last_page` names and
            // this stub leaves empty — the phantom-window shape, not a guess about
            // the ceiling (at ceiling 100 the real walk would fill it).
            const servable = requested <= 20;
            // Inlined rather than a helper with a typed parameter: this directory is
            // linted as plain ES2020 (espree), where an annotation is a PARSE error,
            // and JSDoc does not type a parameter in a `.ts` file (see the two
            // exceptions listed in `eslint.config.js`).
            const rows = Array.from({ length: 50 }, (_, i) =>
                deadLetter({ id: (requested - 1) * 50 + i + 1, recipient: `e2e-dlq-ceil-p${requested}-${i}@example.test` }),
            );
            return route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: servable ? rows : [],
                    meta: { page: requested, per_page: 50, total: 1050, last_page: 21 },
                }),
            });
        });

        await page.getByRole('complementary').getByRole('link', { name: 'Tote Briefe' }).click();
        const main = page.getByRole('main');
        await expect(main.getByRole('row', { name: new RegExp('e2e-dlq-ceil-p1-0@') })).toBeVisible();

        // Walk to the page the counter offers last. Twenty clicks, because that is
        // how an admin gets there — a jump would test a shortcut this page does
        // not have.
        for (let pageNumber = 2; pageNumber <= 21; pageNumber++) {
            await main.getByRole('button', { name: 'Weiter' }).click();
            // Each step waits for ITS counter, so the twenty clicks are twenty
            // served pages and not one fast scroll past twenty pending requests.
            await expect(main.getByText(`Seite ${pageNumber} von 21`)).toBeVisible();
        }
        await expect(main.getByText('Seite 21 von 21')).toBeVisible();

        // The queue is not empty, so the sentence that says it is must not be here.
        await expect(main.getByRole('heading', { name: 'Keine toten Briefe.' })).toHaveCount(0);
        await expect(
            main.getByText('Alle Briefe wurden zugestellt.'),
            'an operator must never read a full dead-letter queue as a healthy one',
        ).toHaveCount(0);
        await expect(main.getByRole('heading', { name: 'Diese Seite ist nicht erreichbar.' })).toBeVisible();
        // The queue's size still comes from the server, so the admin sees that
        // there is something to reach… (`formatDate`/`Intl` renders it as
        // "1.050" in `de`, so the thousands separator is optional in the pattern —
        // measured, not guessed: a bare `/1050 Briefe/` does not match.)
        await expect(main.getByText(/1\.?050 Briefe insgesamt/)).toBeVisible();
        // …and there is a way out of it, not just a sentence.
        const back = main.getByRole('button', { name: 'Zurück auf Seite 1' });
        await expect(back).toBeVisible();

        // The way out really requests page 1 again — "the button exists" is not
        // "the button goes somewhere". A plain `let` and no typed array, because
        // this directory is linted as plain ES2020 (see `eslint.config.js`).
        let requestedPageOne = false;
        page.on('request', (req) => {
            const url = new URL(req.url());
            if (url.pathname.endsWith('/api/admin/failed-mails') && url.searchParams.get('page') === '1') {
                requestedPageOne = true;
            }
        });
        await back.click();
        await expect.poll(() => requestedPageOne).toBe(true);
        await expect(main.getByRole('row', { name: new RegExp('e2e-dlq-ceil-p1-0@') })).toBeVisible();
    });

    test('the reported last page is one the real endpoint can serve', { tag: ['@feature:admin:dlq'] }, async () => {
        // The REAL endpoint, unstubbed, no fixture — the only assertion is about
        // the number it reports, and it is derived from that same answer.
        //
        // What it pins: `meta.last_page` is capped at the scan ceiling (100), which
        // is what removes the empty-last-page control. The cap bites only above
        // `100 × per_page` letters, so on a small stack this passes VACUOUSLY —
        // `last_page` is 1 there whatever the cap does. Stated rather than implied:
        // `test_the_reported_last_page_is_one_the_endpoint_can_serve` (PHPUnit) is
        // the test that carries the claim; this one measures the contract on the
        // endpoint a browser actually talks to.
        const real = await realDeadLetterList();
        expect(real.status, 'the real DLQ list must be readable by a super_admin').toBe(200);
        expect(
            real.body.meta?.last_page ?? 0,
            'last_page must never name a page beyond the scan ceiling',
        ).toBeLessThanOrEqual(100);

        // The page it names really is asked for and really answers — with the SAME queue
        // count it reported a moment ago, which is what "served" means here.
        //
        // Deliberately NOT asserted: that this page holds a letter. `total` is an
        // UPPER BOUND (a `failed_jobs` row with a `mandant_id` that is not a mail
        // job counts without ever yielding a letter), so "the named last page is
        // empty while the queue is not" is a documented state, not a defect —
        // asserting against it would be an assertion about the DATA SET, which this
        // file does not do. What the UI does in that state is the test above.
        const total = real.body.meta?.total ?? 0;
        const lastPageNumber = real.body.meta?.last_page ?? 1;
        const lastPage = await realDeadLetterList({ page: lastPageNumber, perPage: 1 });

        expect(lastPage.status, 'the page the server named must be a served page').toBe(200);
        expect(lastPage.body.meta?.page).toBe(lastPageNumber);
        expect(lastPage.body.meta?.total, 'a served page reports the queue, not a slice').toBe(total);
        expect(
            Array.isArray(lastPage.body.data) ? lastPage.body.data.length : -1,
            'a `per_page=1` page holds at most one letter',
        ).toBeLessThanOrEqual(1);
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
