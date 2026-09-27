import useSWR from 'swr';
import { listMandants } from '../api/client';
import type { Mandant } from '../api/types';

/**
 * Shared SWR cache key of the mandant list.
 *
 * It is deliberately the SAME literal `MandantListPage` passes to its own
 * `useSWR`, so the admin list and the header's domain switcher read ONE cache
 * entry: opening the switcher on `/admin/mandants` costs no second request, and
 * neither surface can show a mandant set the other has not seen. Two different
 * key strings for one resource would silently give the header a stale or empty
 * list — the switcher is a navigation aid, and a wrong target there sends the
 * admin to a domain that is not the mandant he picked.
 */
export const MANDANTS_KEY = '/api/admin/mandants';

export interface UseMandantsResult {
    /** `undefined` while the list is still loading. */
    mandants: Mandant[] | undefined;
    isLoading: boolean;
    error: unknown;
}

/**
 * Every mandant with its domains, `is_active` and `is_primary` — the whole data
 * basis of the domain switcher (`MandantSwitcher`).
 *
 * `enabled: false` passes `null` as the SWR key, which is how SWR is told "do
 * not fetch this yet". The switcher is a `super_admin`-only control, and the
 * list is behind `can:mandants.manage`, so for every other role the request
 * would be a guaranteed 403 on every single admin page load. Not issuing it is
 * not an access-control decision — the API already refuses it — it just keeps
 * the network log free of requests that can only ever fail.
 */
export function useMandants(enabled = true): UseMandantsResult {
    const { data, error, isLoading } = useSWR<Mandant[]>(enabled ? MANDANTS_KEY : null, () => listMandants());

    return { mandants: data, isLoading, error };
}
