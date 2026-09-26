import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SWRConfig } from 'swr';
import { RequireAuth } from './RequireAuth';
import { useAuth } from './useAuth';

const mePayload = {
    id: 1,
    name: 'Admin',
    email: 'admin@example.com',
    current_mandant_id: 1,
    roles: [{ slug: 'super_admin', name: 'Super Admin', mandant_id: null, team_id: null }],
};

const okMe = () =>
    new Response(JSON.stringify({ data: mePayload }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });

const unauthorizedMe = () =>
    new Response(JSON.stringify({ message: 'Unauthenticated.' }), {
        status: 401,
        headers: { 'Content-Type': 'application/json' },
    });

/** A `/me` request that never answers — the "session still unknown" state. */
const hangingMe = () => new Promise<Response>(() => undefined);

let meHandler: () => Response | Promise<Response> = okMe;

function stubFetch() {
    vi.stubGlobal(
        'fetch',
        vi.fn(async (input: RequestInfo | URL) => {
            const url = typeof input === 'string' ? input : String(input);
            if (url === '/api/auth/me') {
                return meHandler();
            }
            if (url === '/api/auth/logout') {
                return new Response(null, { status: 204 });
            }
            return new Response(JSON.stringify({ message: 'not found' }), {
                status: 404,
                headers: { 'Content-Type': 'application/json' },
            });
        }),
    );
}

/** Button that logs out, standing in for the app shell's nav. */
function LogoutButton() {
    const { logout } = useAuth();
    return (
        <button type="button" onClick={() => void logout()}>
            logout
        </button>
    );
}

function renderGuard() {
    // A fresh cache per render: SWR's default cache is module-global, and a
    // `/me` request that never answers would otherwise be deduplicated into
    // the next test. The shell button and the guard share it, exactly like the
    // app's shell/nav hooks do.
    return render(
        <SWRConfig value={{ provider: () => new Map() }}>
            <MemoryRouter initialEntries={['/apply/30']}>
                <LogoutButton />
                <Routes>
                    <Route
                        path="/apply/30"
                        element={
                            <RequireAuth>
                                <p>apply page</p>
                            </RequireAuth>
                        }
                    />
                    <Route path="/login" element={<p>login page</p>} />
                </Routes>
            </MemoryRouter>
        </SWRConfig>,
    );
}

const spinnerIn = (container: HTMLElement) => container.querySelector('.loading-spinner');

afterEach(() => {
    vi.unstubAllGlobals();
    meHandler = okMe;
});

describe('RequireAuth', () => {
    it('shows the spinner instead of redirecting while the session is unknown', async () => {
        // INVARIANT A at the guard: a route that mounts before /me answered must
        // wait. Redirecting here is what bounced a freshly logged-in user back
        // to /login on the page they had just authenticated for.
        stubFetch();
        meHandler = hangingMe;
        const { container } = renderGuard();

        expect(spinnerIn(container)).not.toBeNull();
        expect(screen.queryByText('login page')).toBeNull();
        expect(screen.queryByText('apply page')).toBeNull();
    });

    it('renders the guarded page once /me resolved with a user', async () => {
        stubFetch();
        const { container } = renderGuard();

        expect(await screen.findByText('apply page')).toBeInTheDocument();
        expect(spinnerIn(container)).toBeNull();
    });

    it('redirects to /login when /me answers 401', async () => {
        stubFetch();
        meHandler = unauthorizedMe;
        const { container } = renderGuard();

        expect(await screen.findByText('login page')).toBeInTheDocument();
        expect(spinnerIn(container)).toBeNull();
    });

    it('redirects to /login after a logout without hanging on a spinner', async () => {
        // INVARIANT B at the guard: `logout()` wipes data AND error and leaves
        // nothing in flight. The guard must read that as "answered: no
        // session" instead of spinning forever.
        stubFetch();
        const user = userEvent.setup();
        const { container } = renderGuard();

        expect(await screen.findByText('apply page')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'logout' }));

        expect(await screen.findByText('login page')).toBeInTheDocument();
        await waitFor(() => expect(spinnerIn(container)).toBeNull());
    });
});
