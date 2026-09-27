import useSWR, { mutate as globalMutate, type KeyedMutator } from 'swr';
import { listVenues } from '../api/client';
import type { Venue } from '../api/types';

/**
 * Shared SWR cache key of the HOST-scoped venue list (W12) — the mandant is
 * resolved from the request host by the backend (`MandantContext`). Exported so
 * the combobox and the admin page mutate ONE cache entry: creating a venue
 * inline from inside a team form must be visible in the already-mounted venue
 * list of the same page without a reload.
 */
export const VENUES_KEY = '/api/admin/venues';

/**
 * The cache key of a venue list. `null` is the host-scoped list; a mandant id is
 * the mandant-ADDRESSED list (`/admin/mandants/{id}` is the one admin page that
 * addresses a mandant by URL, and reading the host's venues there is what made
 * its inline create write into the wrong tenant).
 */
export const venuesKey = (mandantId: number | null): string =>
    mandantId === null ? VENUES_KEY : `/api/admin/mandants/${mandantId}/venues`;

/**
 * Every venue list a caller has to refresh after a team / event mutation.
 *
 * Why more than one: the venue list carries the DERIVED `teams_count` /
 * `events_count`, and both surfaces show them. A mutation invalidates the data
 * behind BOTH keys, so a page may never keep a stale count just because it
 * reads the other surface than the one it mutated. Returns the host-scoped key
 * alone when no mandant is addressed (the host-relative pages).
 */
export const venueListKeys = (mandantId: number | null = null): string[] =>
    mandantId === null ? [VENUES_KEY] : [VENUES_KEY, venuesKey(mandantId)];

/**
 * Revalidate every venue list a team / event mutation just invalidated.
 *
 * This exists so the invalidation can never be bound to ONE key literal again:
 * the derivation lives next to `venuesKey()` (see the note there), and callers
 * pass the mandant they are on instead of importing a constant. A key that is
 * not in the list is a stale `teams_count` / `events_count`; a key that is not
 * mounted is a no-op, so over-invalidating costs nothing.
 */
export async function refreshVenueLists(mandantId: number | null = null): Promise<void> {
    await Promise.all(venueListKeys(mandantId).map((key) => globalMutate(key)));
}

export interface UseVenuesResult {
    /** `undefined` while the list is still loading. */
    venues: Venue[] | undefined;
    isLoading: boolean;
    error: unknown;
    mutate: KeyedMutator<Venue[]>;
}

/**
 * The mandant's venue master data.
 *
 * Without `mandantId` the mandant is resolved from the request host by the
 * backend (`MandantContext`), so this hook needs no mandant id — same as
 * `listCategories()` / `listEvents()`. Pass the mandant id on a page that
 * addresses a mandant explicitly (`MandantDetailPage`): the hook then reads and
 * — through the combobox that owns the inline create — writes THAT mandant.
 */
export function useVenues(mandantId: number | null = null): UseVenuesResult {
    const key = venuesKey(mandantId);
    const { data, error, isLoading, mutate } = useSWR<Venue[]>(key, () => listVenues(mandantId));

    return { venues: data, isLoading, error, mutate };
}
