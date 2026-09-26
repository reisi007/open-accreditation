import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SWRConfig } from 'swr';
import { useAuth } from './useAuth';

const mePayload = {
    id: 1,
    name: 'Admin',
    email: 'admin@example.com',
    current_mandant_id: 1,
    roles: [{ slug: 'super_admin', name: 'Super Admin', mandant_id: null, team_id: null }],
};

function AuthProbe() {
    const { user, isAuthenticated, isLoading, login, logout } = useAuth();

    return (
        <div>
            <span data-testid="loading">{String(isLoading)}</span>
            <span data-testid="authenticated">{String(isAuthenticated)}</span>
            <span data-testid="email">{user?.email ?? ''}</span>
            <button type="button" onClick={() => void login('admin@example.com', 'admin')}>
                login
            </button>
            <button type="button" onClick={() => void logout()}>
                logout
            </button>
        </div>
    );
}

/**
 * @param sharedCache Pass a Map to let several probes share one SWR cache, the
 *   way the app's shell/nav/guard hooks do. Defaults to an isolated cache.
 */
function renderProbe(sharedCache?: Map<string, unknown>) {
    return render(
        <SWRConfig value={{ provider: () => sharedCache ?? new Map() }}>
            <AuthProbe />
        </SWRConfig>,
    );
}

/**
 * @param meQueue Per-call handlers for `/api/auth/me`, in order. Missing
 *   entries fall back to a 200 with the admin payload.
 */
function stubFetch(meQueue: Array<() => Response | Promise<Response>> = []) {
    let meCall = 0;
    const queue = meQueue;
    const fetchMock = vi.fn(async (input: RequestInfo | URL, _init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : String(input);
        if (url === '/api/auth/me') {
            const next = queue[meCall];
            meCall += 1;
            if (next) {
                return next();
            }
            return new Response(JSON.stringify({ data: mePayload }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        if (url === '/api/auth/login') {
            return new Response(JSON.stringify({ message: 'ok' }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            });
        }
        if (url === '/api/auth/logout') {
            return new Response(null, { status: 204 });
        }
        return new Response(JSON.stringify({ message: 'not found' }), {
            status: 404,
            headers: { 'Content-Type': 'application/json' },
        });
    });
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

const okMe = () =>
    new Response(JSON.stringify({ data: mePayload }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });

/** A `/me` request that never answers — the "session still unknown" state. */
const hangingMe = () => new Promise<Response>(() => undefined);

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useAuth', () => {
    it('exposes the authenticated user once /me resolves', async () => {
        stubFetch();
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent('admin@example.com'));
        expect(screen.getByTestId('authenticated')).toHaveTextContent('true');
        expect(screen.getByTestId('loading')).toHaveTextContent('false');
    });

    it('stays unauthenticated when /me returns 401', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () =>
                new Response(JSON.stringify({ message: 'Nicht angemeldet.' }), {
                    status: 401,
                    headers: { 'Content-Type': 'application/json' },
                }),
            ),
        );
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('false'));
        expect(screen.getByTestId('authenticated')).toHaveTextContent('false');
        expect(screen.getByTestId('email')).toHaveTextContent('');
    });

    it('login posts credentials and refreshes /me; logout clears the user', async () => {
        const fetchMock = stubFetch();
        const user = userEvent.setup();
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent('admin@example.com'));

        await user.click(screen.getByRole('button', { name: 'logout' }));

        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent(''));

        await user.click(screen.getByRole('button', { name: 'login' }));

        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent('admin@example.com'));

        const loginCall = fetchMock.mock.calls.find(([url]) => url === '/api/auth/login');
        expect(loginCall).toBeDefined();
        const [, init] = loginCall ?? [];
        expect(JSON.parse(String(init?.body))).toEqual({ email: 'admin@example.com', password: 'admin' });
    });

    it('reports loading on a fresh mount until /me answers', async () => {
        // INVARIANT A: before the first `/api/auth/me` response the session
        // question is unanswered, so `isLoading` must be `true`. A route guard
        // that reads `false` here redirects a legitimately authenticated user
        // to /login.
        stubFetch([hangingMe]);
        renderProbe();

        expect(screen.getByTestId('loading')).toHaveTextContent('true');
        expect(screen.getByTestId('authenticated')).toHaveTextContent('false');

        // Still loading — not a "settled with nobody" state that flipped early.
        await new Promise((resolve) => setTimeout(resolve, 20));
        expect(screen.getByTestId('loading')).toHaveTextContent('true');
    });

    it('is settled after a successful login', async () => {
        // INVARIANT A, second half: once `/me` answered with a user, the
        // question is answered — no spinner, user present.
        stubFetch([hangingMe, okMe]);
        const user = userEvent.setup();
        renderProbe();

        expect(screen.getByTestId('loading')).toHaveTextContent('true');

        await user.click(screen.getByRole('button', { name: 'login' }));

        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent('admin@example.com'));
        expect(screen.getByTestId('authenticated')).toHaveTextContent('true');
        expect(screen.getByTestId('loading')).toHaveTextContent('false');
    });

    it('is loading again while a session switch revalidates /me', async () => {
        // Logout answers the question ("nobody"), login must OPEN it again.
        // If the "logged out" marker survived `login()`, every guard mounted
        // after the redirect would report "settled, no user" and bounce the
        // freshly authenticated user back to /login.
        stubFetch([okMe, hangingMe]);
        const user = userEvent.setup();
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent('admin@example.com'));
        await user.click(screen.getByRole('button', { name: 'logout' }));
        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent(''));

        await user.click(screen.getByRole('button', { name: 'login' }));

        await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('true'));
        expect(screen.getByTestId('authenticated')).toHaveTextContent('false');
    });

    it('is settled for a hook that mounts after logout, without re-fetching', async () => {
        // INVARIANT B: `logout()` wipes data AND error and leaves nothing in
        // flight, so "no data, no error" alone would be unreadable. A hook
        // mounted afterwards (route guard on the next navigation) must read
        // "answered: no session" instead of spinning on a `/me` request it
        // does not need to make.
        const fetchMock = stubFetch([okMe, hangingMe]);
        const user = userEvent.setup();
        // One shared cache: the second probe stands in for a route guard that
        // mounts after the logout, exactly like the app's shell hooks do.
        const cache = new Map<string, unknown>();
        renderProbe(cache);

        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent('admin@example.com'));
        await user.click(screen.getByRole('button', { name: 'logout' }));
        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent(''));

        const meCallsBefore = fetchMock.mock.calls.filter(([url]) => url === '/api/auth/me').length;
        const second = renderProbe(cache);
        const secondProbe = within(second.container);

        expect(secondProbe.getByTestId('loading')).toHaveTextContent('false');
        expect(secondProbe.getByTestId('authenticated')).toHaveTextContent('false');
        expect(secondProbe.getByTestId('email')).toHaveTextContent('');
        // Nothing was re-fetched — the answer was already known locally.
        expect(fetchMock.mock.calls.filter(([url]) => url === '/api/auth/me').length).toBe(meCallsBefore);
    });

    it('logout still clears the session when the logout request 401s', async () => {
        // An already-expired session answers 401. `apiLogout()` rejects, and a
        // naive `await apiLogout()` would abort before the cache teardown,
        // leaving the stale session rendered (the caller's navigate never ran).
        vi.stubGlobal(
            'fetch',
            vi.fn(async (input: RequestInfo | URL) => {
                const url = typeof input === 'string' ? input : String(input);
                if (url === '/api/auth/logout') {
                    return new Response(JSON.stringify({ message: 'Nicht angemeldet.' }), {
                        status: 401,
                        headers: { 'Content-Type': 'application/json' },
                    });
                }
                if (url === '/api/auth/me') {
                    return new Response(JSON.stringify({ data: mePayload }), {
                        status: 200,
                        headers: { 'Content-Type': 'application/json' },
                    });
                }
                return new Response(JSON.stringify({ message: 'not found' }), {
                    status: 404,
                    headers: { 'Content-Type': 'application/json' },
                });
            }),
        );
        const user = userEvent.setup();
        renderProbe();

        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent('admin@example.com'));

        await user.click(screen.getByRole('button', { name: 'logout' }));

        await waitFor(() => expect(screen.getByTestId('email')).toHaveTextContent(''));
        expect(screen.getByTestId('authenticated')).toHaveTextContent('false');
        // Post-logout the hook is settled, NOT stuck in a loading state that
        // nothing can ever resolve (the state `unload({revalidate:false})`
        // leaves behind: data and error both undefined, nothing in flight).
        await waitFor(() => expect(screen.getByTestId('loading')).toHaveTextContent('false'));
    });
});
