import { render, screen, waitFor } from '@testing-library/react';
import { SWRConfig } from 'swr';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    FAILED_MAILS_KEY,
    FAILED_MAILS_PER_PAGE,
    useFailedMails,
} from './useFailedMails';
import type { FailedMail } from '../api/types';

/** One row in the exact `FailedMailResource` shape (MEASURED 2026-10-02). */
function row(id: number, recipient: string): FailedMail {
    return {
        id,
        mandant_id: 1,
        mailable: 'App\\Mail\\PassMail',
        recipient,
        queue: 'default',
        exception: 'Connection could not be established with host smtp.example.test:587',
        failed_at: '2026-10-02T09:30:00+00:00',
    };
}

/** `rows` for the first `n` pages, so a paging test needs no hand-written meta. */
function pages(counts: number[], recipients: string[]) {
    return (url: string): { body: unknown } => {
        const page = Number(new URL(url, 'http://x').searchParams.get('page') ?? '1');
        const slice = recipients.slice((page - 1) * FAILED_MAILS_PER_PAGE, page * FAILED_MAILS_PER_PAGE);
        const total = counts.reduce((sum, n) => sum + n, 0);
        const perPage = FAILED_MAILS_PER_PAGE;
        return {
            body: {
                data: slice.map((recipient, i) => row((page - 1) * perPage + i + 1, recipient)),
                meta: {
                    page,
                    per_page: perPage,
                    total,
                    last_page: Math.max(1, Math.ceil(total / perPage)),
                },
            },
        };
    };
}

function stubFetch(respond: (url: string) => Response | { body: unknown; status?: number }) {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : String(input);
        const answer = respond(url);
        if (answer instanceof Response) {
            return answer;
        }
        return json(answer.body, answer.status ?? 200);
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

function json(body: unknown, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function Probe({ page = 1 }: { page?: number }) {
    const { failedMails, meta, isLoading, error } = useFailedMails(page);

    return (
        <div>
            <span data-testid="loading">{String(isLoading)}</span>
            <span data-testid="error">{String(Boolean(error))}</span>
            <span data-testid="rows">{(failedMails ?? []).map((entry) => entry.recipient ?? '').join('|')}</span>
            <span data-testid="meta">
                {meta === undefined ? 'none' : `${meta.page}/${meta.per_page}/${meta.total}/${meta.last_page}`}
            </span>
        </div>
    );
}

/**
 * A FRESH cache per test: SWR's global cache survives between tests in a file,
 * and a settled entry for a key would make a later mount render "answered:
 * nothing" without revalidating. Nothing here needs the shared cache.
 */
function renderProbe(page = 1) {
    return render(
        <SWRConfig value={{ provider: () => new Map() }}>
            <Probe page={page} />
        </SWRConfig>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useFailedMails', () => {
    it('reads the list from the one documented URL, with the page in the key', async () => {
        const fetchMock = stubFetch(() => ({ body: { data: [row(11, 'anna@example.test')], meta: { page: 1, per_page: 50, total: 1, last_page: 1 } } }));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('anna@example.test'));
        expect(screen.getByTestId('loading')).toHaveTextContent('false');
        expect(screen.getByTestId('error')).toHaveTextContent('false');
        expect(screen.getByTestId('meta')).toHaveTextContent('1/50/1/1');

        // The KEY is the URL and must carry the page — one shared key for every
        // page would serve page 1 for all of them.
        expect(fetchMock).toHaveBeenCalledWith(
            `${FAILED_MAILS_KEY}?page=1&per_page=${FAILED_MAILS_PER_PAGE}`,
            expect.anything(),
        );
    });

    it('requests the page it is given, and a DIFFERENT cache key per page', async () => {
        // Two pages of a 120-letter queue. Page 2 must not be answered from a
        // cache entry that belongs to page 1 — that is the whole reason the page
        // number is in the key.
        const fetchMock = stubFetch(
            pages([60, 60], Array.from({ length: 120 }, (_, i) => `m${i + 1}@example.test`)),
        );

        const first = renderProbe(1);
        await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('m1@example.test'));

        first.unmount();
        renderProbe(2);

        await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('m51@example.test'));
        expect(screen.getByTestId('meta')).toHaveTextContent('2/50/120/3');

        const urls = fetchMock.mock.calls.map((call) => String(call[0]));
        expect(urls.some((url) => url.includes('page=1'))).toBe(true);
        expect(urls.some((url) => url.includes('page=2'))).toBe(true);
    });

    it('reports the SERVER window, not a locally assumed one', async () => {
        // `total` is an UPPER BOUND (see `PageMeta`), and the page must render
        // what came back rather than `rows.length` — that is the difference
        // between "12 letters" and "12 of 213 letters".
        stubFetch(() => ({
            body: {
                data: [row(1, 'a@example.test'), row(2, 'b@example.test')],
                meta: { page: 5, per_page: 2, total: 213, last_page: 107 },
            },
        }));
        renderProbe(5);

        await waitFor(() => expect(screen.getByTestId('meta')).toHaveTextContent('5/2/213/107'));
    });

    it('keeps the rows of a response WITHOUT meta, and says one page of that size', async () => {
        // The envelope is reconstructed from the rows, not the other way round:
        // on this queue a hidden row is an undelivered mail nobody can see, so
        // "the server sent letters, the envelope was malformed" must still show
        // the letters. `total: 1` is then honest for an unpaginated answer.
        stubFetch(() => ({ body: { data: [row(1, 'a@example.test')] } }));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('false'));
        expect(screen.getByTestId('rows')).toHaveTextContent('a@example.test');
        expect(screen.getByTestId('error')).toHaveTextContent('false');
        expect(screen.getByTestId('meta')).toHaveTextContent('1/1/1/1');
    });

    it('reports an EMPTY queue as an empty list, not as an error', async () => {
        // The distinction the page renders as two different states: "nothing
        // died" is the good news, "we could not ask" is a failure. If this
        // collapsed into one, an outage would read as a healthy queue.
        stubFetch(() => ({ body: { data: [], meta: { page: 1, per_page: 50, total: 0, last_page: 1 } } }));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('false'));
        expect(screen.getByTestId('rows')).toHaveTextContent('');
        expect(screen.getByTestId('error')).toHaveTextContent('false');
        expect(screen.getByTestId('meta')).toHaveTextContent('1/50/0/1');
    });

    it('surfaces a failed list as an error, never as an empty queue', async () => {
        stubFetch(() => ({ body: { message: 'Server Error' }, status: 500 }));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('true'));
        expect(screen.getByTestId('rows')).toHaveTextContent('');
    });

    it('surfaces the 403 of a role without mails.dlq.manage as an error', async () => {
        stubFetch(() => ({ body: { message: 'This action is unauthorized.' }, status: 403 }));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('true'));
    });

    it('surfaces the 422 of an out-of-range page size as an error, not as an empty queue', async () => {
        // The server validates `per_page` instead of clamping it, so a bad value
        // is an ANSWER. Rendering it as "no dead letters" would be the exact
        // outage-masks-a-healthy-queue defect this page is careful about.
        stubFetch(() => ({ body: { message: 'The per page field must be at most 200.' }, status: 422 }));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('true'));
        expect(screen.getByTestId('rows')).toHaveTextContent('');
    });

    it('sends no filter parameter — the endpoint takes none', async () => {
        // `FailedMailController::index()` reads no filter: a `search=` query
        // string would silently do nothing, which is the kind of drift the docs
        // call out as the first thing that breaks on this surface.
        const fetchMock = stubFetch(() => ({ body: { data: [row(1, 'a@example.test')], meta: { page: 1, per_page: 50, total: 1, last_page: 1 } } }));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('a@example.test'));
        for (const call of fetchMock.mock.calls) {
            const url = String(call[0]);
            expect(url).not.toContain('search');
            expect(url).not.toContain('mandant');
        }
    });

    it('gives a PAGE change a different request, and keeps the reported window', async () => {
        // The hook's whole contract for the page counter: change the page, get a
        // different request. A shared key would serve page 1 for page 2 and the
        // counter would claim otherwise.
        const fetchMock = stubFetch(
            pages([60, 60], Array.from({ length: 120 }, (_, i) => `m${i + 1}@example.test`)),
        );

        const first = renderProbe(1);
        await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('m1@example.test'));
        first.unmount();

        renderProbe(3);
        await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('m101@example.test'));
        expect(screen.getByTestId('meta')).toHaveTextContent('3/50/120/3');

        const urls = fetchMock.mock.calls.map((call) => String(call[0]));
        expect(urls.some((url) => url.includes('page=3'))).toBe(true);
    });
});