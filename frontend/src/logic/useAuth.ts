import useSWR, { useSWRConfig } from 'swr';
import { unload } from 'swr';
import { getMe, login as apiLogin, logout as apiLogout } from '../api/client';
import type { User } from '../api/types';

export function useAuth() {
    const { mutate: configMutate, cache } = useSWRConfig();
    const { data: user, error, isLoading, mutate } = useSWR<User>('/api/auth/me', () => getMe(), {
        shouldRetryOnError: false,
    });

    const login = async (email: string, password: string): Promise<void> => {
        await apiLogin(email, password);
        await configMutate('/api/auth/me');
    };

    const logout = async (): Promise<void> => {
        await apiLogout();
        // A session switch (logout → login as someone else) must start with a
        // clean cache. Two problems with clearing via mutate alone:
        //  1. `configMutate(() => true, undefined, { revalidate: false })`
        //     leaves every key behind as a poisoned entry (`data: undefined`
        //     plus the stale `isLoading/isValidating: false` of the previous
        //     request) — a later mount renders the key as settled (no spinner)
        //     but skips the initial revalidation.
        //  2. It also keeps the stale in-flight `FETCH[key]` dedupe marker, so
        //     even after deleting the entries the fresh mount's revalidation is
        //     dedupe-skipped and the page stays empty until a reload.
        // `unload` (SWR's cache teardown) deletes all entries AND the
        // FETCH/PRELOAD/MUTATION markers on the default cache (the cache the
        // app uses), so the next session starts fresh. `configMutate` still
        // broadcasts `undefined` to mounted hooks of a custom provider cache
        // (the isolated unit-test cache), which `unload` cannot reach.
        await configMutate(() => true, undefined, { revalidate: false });
        for (const key of Array.from(cache.keys())) {
            cache.delete(key);
        }
        unload({ revalidate: false });
    };

    return {
        user,
        isLoading: isLoading || (!user && !error),
        isAuthenticated: Boolean(user),
        login,
        logout,
        mutate,
    };
}
