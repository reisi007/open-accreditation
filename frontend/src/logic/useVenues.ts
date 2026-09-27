import useSWR, { type KeyedMutator } from 'swr';
import { listVenues } from '../api/client';
import type { Venue } from '../api/types';

/**
 * Shared SWR cache key of the mandant-scoped venue list (W12). Exported so the
 * combobox and the admin page mutate ONE cache entry: creating a venue inline
 * from inside a team form must be visible in the already-mounted venue list of
 * the same page without a reload.
 */
export const VENUES_KEY = '/api/admin/venues';

export interface UseVenuesResult {
    /** `undefined` while the list is still loading. */
    venues: Venue[] | undefined;
    isLoading: boolean;
    error: unknown;
    mutate: KeyedMutator<Venue[]>;
}

/**
 * The mandant's venue master data. The mandant itself is resolved from the
 * request host by the backend (`MandantContext`), so this hook needs no
 * mandant id — same as `listCategories()` / `listEvents()`.
 */
export function useVenues(): UseVenuesResult {
    const { data, error, isLoading, mutate } = useSWR<Venue[]>(VENUES_KEY, () => listVenues());

    return { venues: data, isLoading, error, mutate };
}
