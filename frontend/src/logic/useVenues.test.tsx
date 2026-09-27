import { mutate as globalMutate, SWRConfig } from 'swr';
import { render, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Venue } from '../api/types';
import { refreshVenueLists, useVenues, VENUES_KEY, venueListKeys, venuesKey } from './useVenues';

const { listVenuesMock } = vi.hoisted(() => ({
    listVenuesMock: vi.fn(),
}));

vi.mock('../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../api/client')>();
    return { ...actual, listVenues: listVenuesMock };
});

function makeVenue(id: number, name: string): Venue {
    return {
        id,
        name,
        is_active: true,
        teams_count: 0,
        events_count: 0,
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
    };
}

function VenuesProbe({ mandantId }: { mandantId: number | null }) {
    const { venues } = useVenues(mandantId);

    return <span data-testid="venues">{(venues ?? []).map((venue) => venue.name).join('|')}</span>;
}

/**
 * Two deliberate harness choices, both about measuring the invalidation itself:
 *
 * 1. **No `provider`** — the app has no `SWRConfig` provider either, so the
 *    module level `globalMutate` inside `refreshVenueLists()` addresses the very
 *    cache the mounted hook reads. An isolated `new Map()` per test would make
 *    every invalidation a silent no-op and "prove" a refetch that could never
 *    happen in production.
 * 2. **`dedupingInterval: 0`** — SWR swallows a revalidation arriving within 2s
 *    of the last fetch of the same key. A test fires the invalidation
 *    milliseconds after the initial load, so the default would swallow it and
 *    the assertion would measure the rate limiter. A human clicks "save" seconds
 *    after the list arrived, so nothing is deduped in the app.
 *
 * EVERY render in this file goes through this wrapper. A hook mounted outside it
 * registers the default dedupe window on the shared cache and blocks the next
 * test's initial load — a measured trap, not a theory.
 */
function withConfig(node: React.ReactNode) {
    return <SWRConfig value={{ dedupingInterval: 0 }}>{node}</SWRConfig>;
}

function renderProbe(mandantId: number | null) {
    return render(withConfig(<VenuesProbe mandantId={mandantId} />));
}

/** The documented way to empty SWR's global cache (it exports no `cache`). */
async function clearCache(): Promise<void> {
    await globalMutate(() => true, undefined, { revalidate: false });
}

beforeEach(clearCache);
afterEach(async () => {
    await clearCache();
    vi.clearAllMocks();
});

describe('useVenues — the mandant-scoped key', () => {
    it('derives the host-scoped key without a mandant and the addressed one with it', () => {
        expect(venuesKey(null)).toBe('/api/admin/venues');
        expect(venuesKey(7)).toBe('/api/admin/mandants/7/venues');
    });

    it('reads the host mandant without a mandant id', async () => {
        listVenuesMock.mockResolvedValue([makeVenue(1, 'Host Halle')]);

        renderProbe(null);

        await waitFor(() => expect(listVenuesMock).toHaveBeenCalledWith(null));
    });

    it('reads the ADDRESSED mandant when one is given', async () => {
        listVenuesMock.mockResolvedValue([makeVenue(2, 'Zeppelin Arena')]);

        const { getByTestId } = renderProbe(7);

        // The mandant id reaches the request, not just the cache key — a
        // mandant-scoped key with a host-scoped fetcher would show the wrong
        // tenant's venues under the right URL.
        await waitFor(() => expect(listVenuesMock).toHaveBeenCalledWith(7));
        expect(getByTestId('venues')).toHaveTextContent('Zeppelin Arena');
    });

    it('keeps the two surfaces in separate cache entries', async () => {
        listVenuesMock.mockImplementation(async (mandantId: number | null) =>
            mandantId === null ? [makeVenue(1, 'Host Halle')] : [makeVenue(2, 'Zeppelin Arena')],
        );

        const { rerender, getByTestId } = render(withConfig(<VenuesProbe mandantId={null} />));
        await waitFor(() => expect(getByTestId('venues')).toHaveTextContent('Host Halle'));

        // A different mandant must not read the host's cached array …
        rerender(withConfig(<VenuesProbe mandantId={7} />));
        await waitFor(() => expect(getByTestId('venues')).toHaveTextContent('Zeppelin Arena'));

        // … and going back must still find the host entry, with no refetch.
        rerender(withConfig(<VenuesProbe mandantId={null} />));
        expect(getByTestId('venues')).toHaveTextContent('Host Halle');
        expect(listVenuesMock).toHaveBeenCalledTimes(2);
    });
});

describe('venue list invalidation after a team / event mutation', () => {
    it('covers the host-scoped key alone on a host-relative page', () => {
        expect(venueListKeys()).toEqual([VENUES_KEY]);
        expect(venueListKeys(null)).toEqual(['/api/admin/venues']);
    });

    it('covers the addressed key as well, so a stale count is impossible', () => {
        expect(venueListKeys(7)).toEqual(['/api/admin/venues', '/api/admin/mandants/7/venues']);
    });

    it('actually refetches the mounted addressed list', async () => {
        let count = 0;
        listVenuesMock.mockImplementation(async () => {
            count += 1;

            return [makeVenue(2, `Zeppelin Arena ${count}`)];
        });

        const { getByTestId } = renderProbe(7);
        await waitFor(() => expect(getByTestId('venues')).toHaveTextContent('Zeppelin Arena 1'));
        expect(listVenuesMock).toHaveBeenCalledTimes(1);

        // What MandantDetailPage calls after saving a team. Before the key
        // refactor this mutated the host-scoped CONSTANT, which no longer
        // matched the mounted key — the `teams_count` would have stayed stale.
        await refreshVenueLists(7);

        await waitFor(() => expect(getByTestId('venues')).toHaveTextContent('Zeppelin Arena 2'));
        expect(listVenuesMock).toHaveBeenCalledTimes(2);
    });

    it('refreshes a mounted host-scoped list from the same call', async () => {
        let count = 0;
        listVenuesMock.mockImplementation(async () => {
            count += 1;

            return [makeVenue(1, `Host Halle ${count}`)];
        });

        const { getByTestId } = renderProbe(null);
        await waitFor(() => expect(getByTestId('venues')).toHaveTextContent('Host Halle 1'));

        await refreshVenueLists(7);

        await waitFor(() => expect(getByTestId('venues')).toHaveTextContent('Host Halle 2'));
    });
});
