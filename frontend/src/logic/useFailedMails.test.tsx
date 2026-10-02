import { render, screen, waitFor } from '@testing-library/react';
import { SWRConfig } from 'swr';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FAILED_MAILS_KEY, useFailedMails } from './useFailedMails';
import type { FailedMail } from '../api/types';

/** One row in the exact `FailedMailResource` shape (MEASURED 2026-10-02). */
function payload(): FailedMail[] {
    return [
        {
            id: 11,
            mandant_id: 1,
            mailable: 'App\\Mail\\PassMail',
            recipient: 'anna@example.test',
            queue: 'default',
            exception: 'Connection could not be established with host smtp.example.test:587',
            failed_at: '2026-10-02T09:30:00+00:00',
        },
    ];
}

function stubFetch(respond: (url: string) => Response) {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : String(input);
        return respond(url);
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

function json(body: unknown, status = 200) {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

function Probe() {
    const { failedMails, isLoading, error } = useFailedMails();

    return (
        <div>
            <span data-testid="loading">{String(isLoading)}</span>
            <span data-testid="error">{String(Boolean(error))}</span>
            <span data-testid="rows">{(failedMails ?? []).map((entry) => entry.recipient ?? '').join('|')}</span>
        </div>
    );
}

/**
 * A FRESH cache per test: SWR's global cache survives between tests in a file,
 * and a settled `undefined` entry would make a later mount render "answered:
 * nothing" without revalidating. Nothing here needs the shared cache.
 */
function renderProbe() {
    return render(
        <SWRConfig value={{ provider: () => new Map() }}>
            <Probe />
        </SWRConfig>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useFailedMails', () => {
    it('reads the list from the one documented URL', async () => {
        const fetchMock = stubFetch(() => json({ data: payload() }));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('anna@example.test'));
        expect(screen.getByTestId('loading')).toHaveTextContent('false');
        expect(screen.getByTestId('error')).toHaveTextContent('false');
        expect(fetchMock).toHaveBeenCalledWith(FAILED_MAILS_KEY, expect.anything());
    });

    it('reports an EMPTY queue as an empty list, not as an error', async () => {
        // The distinction the page renders as two different states: "nothing
        // died" is the good news, "we could not ask" is a failure. If this
        // collapsed into one, an outage would read as a healthy queue.
        stubFetch(() => json({ data: [] }));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('false'));
        expect(screen.getByTestId('rows')).toHaveTextContent('');
        expect(screen.getByTestId('error')).toHaveTextContent('false');
    });

    it('surfaces a failed list as an error, never as an empty queue', async () => {
        stubFetch(() => json({ message: 'Server Error' }, 500));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('true'));
        expect(screen.getByTestId('rows')).toHaveTextContent('');
    });

    it('surfaces the 403 of a role without mails.dlq.manage as an error', async () => {
        stubFetch(() => json({ message: 'This action is unauthorized.' }, 403));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('true'));
    });

    it('sends no filter parameters — the endpoint takes none', async () => {
        // `FailedMailController::index()` reads the whole table. A query string
        // would silently do nothing, which is the kind of drift the docs call
        // out as the first thing that breaks on this surface.
        const fetchMock = stubFetch(() => json({ data: payload() }));
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('rows')).toHaveTextContent('anna@example.test'));
        for (const call of fetchMock.mock.calls) {
            expect(String(call[0])).not.toContain('?');
        }
    });
});
