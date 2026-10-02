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
 * Serves `GET /api/admin/failed-mails` with `rows` and lets the requeue through
 * to the REAL backend — so a 404 the page shows is the backend's own answer, not
 * a number a stub produced.
 */
export async function stubDeadLetterList(page: Page, rows: DeadLetterFixture[]): Promise<void> {
    await page.route(DEAD_LETTER_LIST_PATTERN, async (route) => {
        if (route.request().method() === 'GET') {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: rows }),
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
    body: { data?: unknown[] } & Record<string, unknown>;
}

/**
 * The real, unstubbed DLQ list — what a `super_admin` sees on this stack.
 *
 * This is what makes the "empty queue is its own state" assertion a measurement:
 * the E2E stack really has no dead letters (no worker), so the empty state is
 * reached through the real endpoint.
 */
export async function realDeadLetterList(): Promise<RealDeadLetterList> {
    const api = await loginAdminApi();
    try {
        const response = await api.get('/api/admin/failed-mails');
        return { status: response.status(), body: (await response.json()) as RealDeadLetterList['body'] };
    } finally {
        await api.dispose();
    }
}
