import type { Page } from '@playwright/test';
import { loginAdminApi } from './api-session';

/**
 * One dead letter in the exact `FailedMailResource` shape, MEASURED against the
 * running backend on 2026-10-02 (`GET /api/admin/failed-mails` → `{"data":[]}`).
 *
 * ## Why the payload is fulfilled at the API boundary instead of written to the DB
 *
 * There is **no route** that creates a `failed_jobs` row — the dead-letter queue
 * is filled by the WORKER when a job exhausts `$tries`, and the E2E stack runs no
 * worker (`php artisan serve` only; see `AGENTS.todo.md` Position 46). So the
 * only reachable way to show a non-empty DLQ over HTTP is to answer the list
 * endpoint with the row the real endpoint would have returned.
 *
 * That is a deliberate, bounded trade and it is stated here rather than left to
 * be discovered:
 *
 *  - **What stays real:** the login, the route, the page, every assertion about
 *    rendering, the confirm dialog, and — in `stubDeadLetterList` — the
 *    `POST …/requeue`, which is `route.continue()`d to the real backend.
 *  - **What is fulfilled:** `GET /api/admin/failed-mails` with the shape
 *    `FailedMailResource` produces, so the UI is exercised against the contract
 *    as MEASURED rather than against a guess. The one spec that needs a 200 with
 *    a body of its own (`admin-dlq.spec.ts`'s success case) stubs the requeue
 *    too, because a requeue that really succeeded needs a real row.
 *
 * The alternative — inserting the row through `psql` — would be a second truth
 * about the schema in a TypeScript test file, and it would need a live DB handle
 * the suite deliberately does not have (every other helper speaks HTTP).
 *
 * ## What this fixture CANNOT prove, named so nobody reads more into it
 *
 * That the backend really dead-letters a mail after five attempts, that
 * `mandant_id` is really written, and that a `mandant_admin` really sees only
 * his own. Those are backend facts, covered by `MailDeadLetterTest` in
 * `backend/tests/`; an E2E that inserted rows by hand would not have measured
 * them either.
 */
export interface DeadLetterFixture {
    id: number;
    mandant_id: number | null;
    mailable: string | null;
    recipient: string | null;
    queue: string;
    exception: string;
    failed_at: string | null;
}

/**
 * The page window the real endpoint reports — `AnonymousResourceCollection`'s
 * `meta`, which `FailedMailController::index()` fills with
 * `page` / `per_page` / `total` / `last_page`.
 *
 * MEASURED default: `per_page` 50 for a request that names none
 * (`FailedMailController::PER_PAGE_DEFAULT`), and `last_page` is
 * `max(1, ceil(total / per_page))` — so an EMPTY queue answers `last_page: 1`,
 * not 0.
 */
export interface DeadLetterMeta {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
}

/** A window for `count` letters on `perPage` rows, served on `page`. */
export function deadLetterMeta(
    count: number,
    overrides: Partial<DeadLetterMeta> & { per_page?: number } = {},
): DeadLetterMeta {
    const perPage = overrides.per_page ?? count;
    const total = overrides.total ?? count;

    return {
        page: overrides.page ?? 1,
        per_page: perPage,
        total,
        last_page: overrides.last_page ?? Math.max(1, Math.ceil(total / perPage)),
    };
}

/**
 * The recipient addresses the specs address rows by.
 *
 * Module constants rather than literals inside the specs, because the specs match
 * them through `new RegExp(...)` — and a fixture value that appears twice is a
 * fixture value that can drift.
 */
export const REFUSED_RECIPIENT = 'e2e-dlq-verweigert@example.test';
export const REQUEUED_RECIPIENT = 'e2e-dlq-erneut@example.test';
export const FIRST_RECIPIENT = 'e2e-dlq-alpha@example.test';
export const SECOND_RECIPIENT = 'e2e-dlq-beta@example.test';

/**
 * The server-side cut, built the way `Str::limit($text, 500)` builds it: 497
 * characters plus the `...` end marker. The page must label THIS one and leave a
 * short exception alone.
 */
export function cutException(): string {
    return `${'E'.repeat(497)}...`;
}

/** A short exception — the shape a trace has when it fits under the 500-char cut. */
export const WHOLE_EXCEPTION =
    'Symfony\\Component\\Mailer\\Exception\\TransportException: Connection could not be established with host smtp.example.test:587';

export function deadLetter(overrides: Partial<DeadLetterFixture> = {}): DeadLetterFixture {
    return {
        id: 4242,
        mandant_id: 1,
        mailable: 'App\\Mail\\PassMail',
        recipient: REFUSED_RECIPIENT,
        queue: 'default',
        exception: WHOLE_EXCEPTION,
        failed_at: '2026-10-02T09:30:00+00:00',
        ...overrides,
    };
}

/**
 * `GET /api/admin/failed-mails` and `POST /api/admin/failed-mails/{id}/requeue`
 * are TWO URL shapes, and Playwright's glob `*` does not cross `/`
 * (MEASURED 2026-10-02: the list glob does NOT match the requeue path, so a
 * handler registered on the list pattern alone silently let the ACTION reach the
 * real backend — which is how the first version of the success case ended up
 * asserting a 404 it never stubbed). Both patterns are therefore declared below,
 * and the test that needs a stubbed requeue registers its own handler on
 * `DEAD_LETTER_REQUEUE_PATTERN`.
 *
 * The patterns themselves are spelled out rather than quoted here: a glob starts
 * with two asterisks and a slash, and `*` + `/` inside a block comment CLOSES it
 * (MEASURED on this very edit — `tsc` answered "Invalid character" on the line
 * after, and eslint a parse error). Naming them in prose costs nothing and keeps
 * the comment from eating the file.
 */
export const DEAD_LETTER_LIST_PATTERN = '**/api/admin/failed-mails*';
export const DEAD_LETTER_REQUEUE_PATTERN = '**/api/admin/failed-mails/*/requeue';

/**
 * Serves `GET /api/admin/failed-mails` with `rows` — SERVED PAGE BY PAGE, the
 * way the real endpoint answers since 2026-10-06 — and lets the requeue through
 * to the REAL backend, so a 404 the page shows is the backend's own answer and
 * not a number a stub produced.
 *
 * ## Why the stub paginates instead of returning one big array
 *
 * Returning all rows on every request would make the page look right while
 * measuring none of what the change is about: the counter, the window and the
 * "next" button all read `meta`, and a stub without `meta` cannot fail when the
 * page renders the wrong one. Slicing `rows` by the requested page also means a
 * test can state "three pages of two" as DATA instead of asserting a count it
 * typed itself.
 *
 * `meta` is derived from the whole set, never from the slice — that is what lets
 * the last page know it is the last page.
 *
 * ## `perPage` CAPS the window, it does not replace it — and that is load-bearing
 *
 * MEASURED 2026-10-06, the first version of the paging spec failed on exactly
 * this: the page always requests `per_page=50` (`FAILED_MAILS_PER_PAGE`, the UI's
 * own constant), so a stub that only USES `perPage` as a fallback for a missing
 * query parameter silently served all six rows as one page and the counter
 * never appeared. So the served window is `min(requested, perPage)` and the
 * `meta.per_page` that comes back is the size actually served — a test that caps
 * the window gets a consistent `last_page` with it.
 *
 * A test that wants the REAL window instead of a capped one omits `perPage` and
 * supplies more than `FAILED_MAILS_PER_PAGE` rows.
 */
export async function stubDeadLetterList(
    page: Page,
    rows: DeadLetterFixture[],
    options: { perPage?: number } = {},
): Promise<void> {
    await page.route(DEAD_LETTER_LIST_PATTERN, async (route) => {
        if (route.request().method() === 'GET') {
            const url = new URL(route.request().url());
            const requested = Number(url.searchParams.get('page') ?? '1');
            const asked = Number(url.searchParams.get('per_page') ?? '50');
            const size = options.perPage === undefined ? asked : Math.min(asked, options.perPage);
            const window = Number.isFinite(requested) && requested > 0 ? requested : 1;
            const slice = rows.slice((window - 1) * size, window * size);

            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    data: slice,
                    // `page` ECHOES the request, exactly as the real endpoint does
                    // (`FailedMailController::index()` answers with the `page` it
                    // resolved). MEASURED 2026-10-06: leaving it at the `deadLetterMeta`
                    // default of 1 made the counter sit on "Seite 1 von 3" after
                    // "Weiter" had demonstrably fetched page 2 — the rows updated and
                    // the heading did not.
                    meta: deadLetterMeta(rows.length, { page: window, per_page: size }),
                }),
            });
            return;
        }
        await route.continue();
    });
    await page.route(DEAD_LETTER_REQUEUE_PATTERN, (route) => route.continue());
}

/**
 * The id the requeue of a stubbed row addresses — and the answer it really gets.
 *
 * MEASURED 2026-10-02 against the running dev backend:
 * `POST /api/admin/failed-mails/2147483647/requeue` → **404**. The spec asserts
 * that status itself before using the row, so "the UI surfaces the refusal" is
 * anchored to a real answer rather than to a number someone typed into a stub.
 */
export const UNKNOWN_DEAD_LETTER_ID = 2147483647;

/**
 * The wording both "I ordered a delivery" endpoints answer with, MEASURED on the
 * running backend on 2026-10-02:
 *
 *  - `FailedMailController::requeue:99`
 *  - `AdminApplicationController::resend:181` and `:196`
 *
 * "in die Warteschlange gestellt", because since Position 45
 * `MandantMailerService::send()` only dispatches `SendMandantMail` — neither
 * endpoint can know whether the relay answered. One constant for both, because
 * they are the same sentence from the same decision, and two copies of a
 * measured string are two strings that can drift from the server.
 */
export const REAL_REQUEUE_MESSAGE = 'E-Mail wurde erneut in die Warteschlange gestellt.';

/** The real status the backend gives a requeue of a letter that does not exist. */
export async function realRequeueStatusForUnknownLetter(): Promise<number> {
    const api = await loginAdminApi();
    try {
        const response = await api.post(`/api/admin/failed-mails/${UNKNOWN_DEAD_LETTER_ID}/requeue`);
        return response.status();
    } finally {
        await api.dispose();
    }
}

export interface RealDeadLetterList {
    status: number;
    body: { data?: unknown[]; meta?: DeadLetterMeta } & Record<string, unknown>;
}

/**
 * The real, unstubbed DLQ list — what a `super_admin` sees on this stack.
 *
 * It measures the ENDPOINT: the status a super_admin really gets and the
 * envelope shape the page depends on. Its LENGTH is deliberately left to the
 * caller to interpret and never asserted as zero — the dead-letter table is
 * accumulated data, so "this stack is empty" is a statement about a data set
 * that can change without any product code changing (MEASURED 2026-10-03: one
 * real `failed_jobs` row is enough to turn the previous emptiness assertion of
 * `admin-dlq.spec.ts` red while the page renders correctly). Callers derive
 * their expectations from what came back; the empty state itself is served on
 * purpose via `stubDeadLetterList(page, [])`.
 */
export async function realDeadLetterList(
    params: { page?: number; perPage?: number } = {},
): Promise<RealDeadLetterList> {
    const api = await loginAdminApi();
    try {
        const query = new URLSearchParams();
        if (params.page !== undefined) query.set('page', String(params.page));
        if (params.perPage !== undefined) query.set('per_page', String(params.perPage));
        const suffix = query.toString() === '' ? '' : `?${query.toString()}`;
        const response = await api.get(`/api/admin/failed-mails${suffix}`);
        return { status: response.status(), body: (await response.json()) as RealDeadLetterList['body'] };
    } finally {
        await api.dispose();
    }
}
