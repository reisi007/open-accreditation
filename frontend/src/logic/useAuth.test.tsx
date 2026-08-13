import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SWRConfig } from 'swr';
import { useAuth } from './useAuth';

const mePayload = {
    id: 1,
    name: 'Admin',
    email: 'admin@example.com',
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

function renderProbe() {
    return render(
        <SWRConfig value={{ provider: () => new Map() }}>
            <AuthProbe />
        </SWRConfig>,
    );
}

function stubFetch() {
    const fetchMock = vi.fn(async (input: RequestInfo | URL, _init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input instanceof URL ? input.pathname : String(input);
        if (url === '/api/auth/me') {
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
});
