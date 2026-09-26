import useSWR, { useSWRConfig } from 'swr';
import { unload } from 'swr';
import { getMe, login as apiLogin, logout as apiLogout } from '../api/client';
import type { User } from '../api/types';

const SESSION_KEY = '/api/auth/me';

/**
 * Local, network-free record of "the user explicitly logged out".
 *
 * It lives in the same app-wide SWR cache as `/api/auth/me` on purpose: every
 * `useAuth()` instance shares one answer (the app renders several at once —
 * shell nav, page, route guard), and a full page load starts from an empty
 * cache, which is exactly the "no explicit logout happened" state.
 */
const LOGGED_OUT_KEY = 'auth/logged-out';

export function useAuth() {
    const { mutate: configMutate, cache } = useSWRConfig();
    const { data: user, error, mutate } = useSWR<User>(SESSION_KEY, () => getMe(), {
        shouldRetryOnError: false,
    });
    // `null` as the fetcher: SWR never requests this key, it only mirrors the
    // entry that `login()`/`logout()` below write. `undefined` = no explicit
    // logout, `true` = the user asked to be logged out.
    const { data: loggedOut } = useSWR<boolean>(LOGGED_OUT_KEY, null, { revalidateOnMount: false });

    const login = async (email: string, password: string): Promise<void> => {
        await apiLogin(email, password);
        // The server issued a session, so the question is open again: clear the
        // "answered" marker BEFORE revalidating `/me`. A guard mounted right
        // after the caller's `navigate()` must read "still loading", not
        // "answered: nobody" — otherwise the redirect to the page the user
        // just logged in for bounces straight back to /login.
        await configMutate(LOGGED_OUT_KEY, false, { revalidate: false });
        await configMutate(SESSION_KEY);
    };

    const logout = async (): Promise<void> => {
        // A failed logout request must not skip the cache teardown below: an
        // already-expired session answers 401 and `apiLogout()` rejects, which
        // used to leave the stale session rendered (the caller never reached
        // its `navigate`). Clearing locally is correct in every failure mode —
        // 401 means the session is gone, a network/5xx error still means the
        // user asked to be logged out. `logout()` therefore never rejects.
        await apiLogout().catch(() => undefined);
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
        // MUST come after the teardown: `unload()` and the `cache.delete` loop
        // drop the marker along with everything else. Writing it before would
        // leave the hook in the unanswered state (no data, no error, nothing
        // in flight) — the permanent-spinner bug this marker exists to prevent.
        await configMutate(LOGGED_OUT_KEY, true, { revalidate: false });
    };

    return {
        user,
        /**
         * INVARIANT — `isLoading` means: *the question "is there a session?"
         * has not been answered yet, and nothing received so far answers it.*
         *
         * The question is answered by exactly three things:
         *   1. `user`      — `/api/auth/me` resolved (a session exists).
         *   2. `error`     — `/api/auth/me` failed; a 401 IS the answer
         *                     "no session", so the guard must redirect instead
         *                     of spinning.
         *   3. `loggedOut` — the user explicitly called `logout()`. The cache
         *                     teardown in `logout()` destroys data AND error,
         *                     so without this marker the hook could never leave
         *                     the loading state and every caller would spin
         *                     forever.
         *
         * Both directions matter, which is why neither alternative works alone:
         *   - a bare "no data, no error" (the pre-regression shape) spins
         *     forever after `logout()`, because the teardown destroys the error
         *     that used to serve as the "no session" answer;
         *   - SWR's own `isLoading` is only true while a request is IN FLIGHT,
         *     so it inherits whatever `isLoading` the cache entry happens to
         *     carry. On a mount that reads an already-settled entry it reports
         *     "answered" — that is what a route guard mounting after a logout
         *     saw, turning a session question nobody asked yet into a redirect.
         *     Deriving the flag from the three answers above does not depend on
         *     that internal at all.
         */
        isLoading: !loggedOut && !user && !error,
        isAuthenticated: Boolean(user),
        login,
        logout,
        mutate,
    };
}
