import { renderWithProviders } from '../../test-setup';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SWRConfig } from 'swr';
import type { AdminUser } from '../../api/types';
import { UsersPage } from './UsersPage';

const { listUsersMock, updateUserRolesMock, userList } = vi.hoisted(() => {
    const userList: AdminUser[] = [];
    return {
        userList,
        listUsersMock: vi.fn(async (params?: { search?: string }) => (params?.search ? [] : userList)),
        updateUserRolesMock: vi.fn(),
    };
});

vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return { ...actual, listUsers: listUsersMock, updateUserRoles: updateUserRolesMock };
});

function makeUser(id: number): AdminUser {
    return {
        id,
        name: `User ${id}`,
        email: `user${id}@example.test`,
        roles: [{ role: { slug: 'user', name: 'User' }, mandant_id: null, team_id: null, team: null }],
    };
}

function setUsers(users: AdminUser[]): void {
    userList.splice(0, userList.length, ...users);
}

function renderPage() {
    return renderWithProviders(
        <SWRConfig value={{ provider: () => new Map() }}>
            <UsersPage />
        </SWRConfig>,
    );
}

afterEach(() => {
    vi.clearAllMocks();
});

describe('UsersPage', () => {
    it('shows the result count and paginates the user list', async () => {
        setUsers(Array.from({ length: 25 }, (_, index) => makeUser(index + 1)));
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('25 Benutzer', { exact: true });
        expect(screen.getByText('Seite 1 von 2', { exact: true })).toBeInTheDocument();
        expect(screen.getAllByRole('button', { name: 'Rollen bearbeiten' })).toHaveLength(20);
        expect(screen.getByRole('button', { name: 'Zurück' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Weiter' })).toBeEnabled();

        await user.click(screen.getByRole('button', { name: 'Weiter' }));
        await waitFor(() =>
            expect(screen.getAllByRole('button', { name: 'Rollen bearbeiten' })).toHaveLength(5),
        );
        expect(screen.getByText('Seite 2 von 2', { exact: true })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Zurück' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Weiter' })).toBeDisabled();

        await user.click(screen.getByRole('button', { name: 'Zurück' }));
        await waitFor(() =>
            expect(screen.getAllByRole('button', { name: 'Rollen bearbeiten' })).toHaveLength(20),
        );
        expect(screen.getByText('Seite 1 von 2', { exact: true })).toBeInTheDocument();
    });

    it('exposes the full name and email via title for truncation', async () => {
        setUsers([makeUser(1)]);
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        expect(screen.getByTitle('User 1')).toHaveTextContent('User 1');
        expect(screen.getByTitle('user1@example.test')).toHaveTextContent('user1@example.test');
    });

    it('shows the no-users empty state without a search query', async () => {
        setUsers([]);
        renderPage();

        expect(await screen.findByText('Noch keine Benutzer vorhanden.')).toBeInTheDocument();
        expect(screen.queryByText('Keine Benutzer für die Suche.')).not.toBeInTheDocument();
    });

    it('shows the search-specific empty state and resets the page on a new search', async () => {
        setUsers(Array.from({ length: 25 }, (_, index) => makeUser(index + 1)));
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('25 Benutzer', { exact: true });
        await user.click(screen.getByRole('button', { name: 'Weiter' }));
        await screen.findByText('Seite 2 von 2', { exact: true });

        await user.type(screen.getByLabelText('Benutzer suchen'), 'kein-treffer');
        await waitFor(() => expect(screen.getByText('Keine Benutzer für die Suche.')).toBeInTheDocument(), {
            timeout: 2000,
        });
        expect(screen.queryByRole('group', { name: 'Seitennavigation' })).not.toBeInTheDocument();
        expect(screen.queryByText('Seite 2 von 2')).not.toBeInTheDocument();
    });
});
