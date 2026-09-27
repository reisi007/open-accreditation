import { render, screen, waitFor } from '@testing-library/react';
import { SWRConfig } from 'swr';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useMandants } from './useMandants';

const MANDANTS_URL = '/api/admin/mandants';

function payload() {
    return [
        { id: 1, slug: 'hauptseite', name: 'Hauptseite', is_active: true, is_primary: true, domains: [{ id: 1, hostname: 'localhost' }] },
        { id: 2, slug: 'bundesliga', name: 'Bundesliga', is_active: true, is_primary: false, domains: [{ id: 2, hostname: 'bundesliga.test' }] },
    ];
}

function stubFetch(status = 200) {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : String(input);
        if (url === MANDANTS_URL) {
            return new Response(JSON.stringify({ data: payload() }), {
                status,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        return new Response(JSON.stringify({ message: 'not found' }), {
            status: 404,
            headers: { 'Content-Type': 'application/json' },
        });
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

function Probe() {
    const { mandants, isLoading, error } = useMandants();

    return (
        <div>
            <span data-testid="loading">{String(isLoading)}</span>
            <span data-testid="error">{String(Boolean(error))}</span>
            <span data-testid="mandants">{(mandants ?? []).map((mandant) => mandant.name).join('|')}</span>
        </div>
    );
}

function DisabledProbe() {
    const { mandants } = useMandants(false);

    return <span data-testid="mandants">{(mandants ?? []).length}</span>;
}

/**
 * A FRESH cache per test (`provider: () => new Map()`), deliberately: SWR's
 * global cache survives between tests in a file, and emptying it with a global
 * `mutate(() => true, undefined, …)` leaves a POISONED entry behind — present,
 * settled, holding `undefined` — which a later mount renders as "answered:
 * nothing" and never revalidates. Nothing in the assertions needs the global
 * cache: the two-probe test measures two consumers of one key inside ONE app
 * instance, which is exactly the production situation the shared key exists for.
 */
function renderProbe(node: React.ReactNode = <Probe />) {
    return render(<SWRConfig value={{ provider: () => new Map() }}>{node}</SWRConfig>);
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useMandants', () => {
    it('reads the list from the one path the mandant page uses', async () => {
        const fetchMock = stubFetch();
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('mandants')).toHaveTextContent('Hauptseite|Bundesliga'));
        expect(screen.getByTestId('loading')).toHaveTextContent('false');
        expect(screen.getByTestId('error')).toHaveTextContent('false');
        // The exact URL, not "some URL that ends in mandants": the switcher must
        // land in the SAME cache entry as `MandantListPage`, or the header would
        // issue a second request for data the page already has.
        expect(fetchMock).toHaveBeenCalledWith(MANDANTS_URL, expect.anything());
    });

    it('shares ONE request between two consumers of the key', async () => {
        const fetchMock = stubFetch();
        renderProbe(
            <>
                <Probe />
                <Probe />
            </>,
        );

        await waitFor(() => expect(screen.getAllByTestId('mandants')[0]).toHaveTextContent('Hauptseite|Bundesliga'));
        // Both probes read the same array out of the same entry …
        expect(screen.getAllByTestId('mandants')[1]).toHaveTextContent('Hauptseite|Bundesliga');
        // … which is only possible if the key is a shared identity and not a
        // per-instance one.
        expect(fetchMock.mock.calls.filter(([url]) => url === MANDANTS_URL)).toHaveLength(1);
    });

    it('surfaces a failed list as an error, not as an empty list', async () => {
        stubFetch(500);
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('error')).toHaveTextContent('true'));
        expect(screen.getByTestId('mandants')).toHaveTextContent('');
    });

    it('issues no request at all when disabled', async () => {
        const fetchMock = stubFetch();
        renderProbe(<DisabledProbe />);

        await waitFor(() => expect(screen.getByTestId('mandants')).toHaveTextContent('0'));
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
