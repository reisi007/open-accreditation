import { renderWithProviders } from '../../test-setup';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { SWRConfig } from 'swr';
import { ApiError } from '../../api/client';
import type { AdminUser, User } from '../../api/types';
import { UsersPage } from './UsersPage';

const { currentUser, deleteUserAccountMock, listUsersMock, updateUserRolesMock, userList } = vi.hoisted(() => {
    const userList: AdminUser[] = [];
    return {
        userList,
        // The session the page reads its permission from. `super_admin` is the
        // default; the gate test overrides it.
        currentUser: { current: null as User | null },
        listUsersMock: vi.fn(async (params?: { search?: string }) => (params?.search ? [] : userList)),
        updateUserRolesMock: vi.fn(),
        deleteUserAccountMock: vi.fn(),
    };
});

vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return {
        ...actual,
        listUsers: listUsersMock,
        updateUserRoles: updateUserRolesMock,
        deleteUserAccount: deleteUserAccountMock,
    };
});

vi.mock('../../logic/useAuth', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../logic/useAuth')>();
    return { ...actual, useAuth: () => ({ user: currentUser.current, isAuthenticated: true, isLoading: false }) };
});

function sessionWith(...slugs: string[]): User {
    return {
        id: 99,
        name: 'Admin',
        email: 'admin@example.test',
        current_mandant_id: 1,
        roles: slugs.map((slug) => ({ slug, name: slug, mandant_id: 1, team_id: null })),
    };
}

function makeUser(id: number, counts: { applications?: number; subApplications?: number } = {}): AdminUser {
    return {
        id,
        name: `User ${id}`,
        email: `user${id}@example.test`,
        applications_count: counts.applications ?? 0,
        sub_applications_count: counts.subApplications ?? 0,
        roles: [{ role: { slug: 'user', name: 'User' }, mandant_id: null, team_id: null, team: null }],
    };
}

function setUsers(users: AdminUser[]): void {
    userList.splice(0, userList.length, ...users);
}

function renderPage() {
    return renderWithProviders(
        <SWRConfig value={{ provider: () => new Map() }}>
            <MemoryRouter>
                <UsersPage />
            </MemoryRouter>
        </SWRConfig>,
    );
}

afterEach(() => {
    vi.clearAllMocks();
    currentUser.current = null;
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

/**
 * Account termination (DSGVO) on the admin surface.
 *
 * The gate under test is the UI one — `canDeleteUserAccounts` over the session
 * roles, which is the plumbing `/api/auth/me` actually provides. The backend
 * `can:users.delete` gate stays the authorisation; the button must not be
 * offered to a role the API would refuse.
 */
/**
 * F4 (Nutzerentscheid 2026-10-06): role assignment and account termination are
 * two permissions, and a `team_admin` holds the first but not the second.
 *
 * The page is the place where that difference becomes visible: he reaches it,
 * sees the roles and edits them — and gets no delete action and no role
 * checkbox the backend would answer 403 to.
 */
describe('UsersPage role assignment', () => {
    function teamAdminOf(overrides: Partial<AdminUser> = {}): AdminUser {
        return {
            ...makeUser(1),
            roles: [{ role: { slug: 'team_admin', name: 'Team Admin' }, mandant_id: 1, team_id: 1, team: { id: 1, name: 'Team A' } }],
            ...overrides,
        };
    }

    it('offers a mandant_admin every role checkbox', async () => {
        currentUser.current = sessionWith('mandant_admin');
        setUsers([makeUser(1)]);
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        await user.click(screen.getByRole('button', { name: 'Rollen bearbeiten' }));

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByLabelText('Mandant-Admin')).toBeInTheDocument();
        expect(within(dialog).getByLabelText('Team-Admin')).toBeInTheDocument();
        expect(within(dialog).getByLabelText('Verifizierer')).toBeInTheDocument();
        expect(within(dialog).queryByText(/nur innerhalb deines Teams/)).not.toBeInTheDocument();
    });

    it('offers a team_admin ONLY the team_admin checkbox, and says why', async () => {
        currentUser.current = sessionWith('team_admin');
        setUsers([teamAdminOf()]);
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        await user.click(screen.getByRole('button', { name: 'Rollen bearbeiten' }));

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByLabelText('Team-Admin')).toBeInTheDocument();
        // The three roles a team_admin may NOT write are not offered at all —
        // the backend answers 403 for each of them.
        expect(within(dialog).queryByLabelText('Mandant-Admin')).not.toBeInTheDocument();
        expect(within(dialog).queryByLabelText('Verifizierer')).not.toBeInTheDocument();
        expect(within(dialog).queryByLabelText('Benutzer', { exact: true })).not.toBeInTheDocument();
        expect(within(dialog).getByText(/nur innerhalb deines Teams/)).toBeInTheDocument();
        expect(within(dialog).getByText(/Konten beenden darfst du nicht/)).toBeInTheDocument();
    });

    /**
     * A mandant-level role the target already holds must NOT leak into the
     * payload: `UserController::updateRoles()` REPLACES the role set, so an
     * unfiltered payload from a `team_admin` would be a 403 on every save (and,
     * before F4, a mandant-wide rewrite).
     */
    it('sends only the assignable roles, even when the target holds more', async () => {
        currentUser.current = sessionWith('team_admin');
        setUsers([
            teamAdminOf({
                roles: [
                    { role: { slug: 'team_admin', name: 'Team Admin' }, mandant_id: 1, team_id: 1, team: { id: 1, name: 'Team A' } },
                    { role: { slug: 'user', name: 'User' }, mandant_id: 1, team_id: null, team: null },
                ],
            }),
        ]);
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        await user.click(screen.getByRole('button', { name: 'Rollen bearbeiten' }));
        await user.click(await screen.findByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(updateUserRolesMock).toHaveBeenCalledTimes(1));
        expect(updateUserRolesMock).toHaveBeenCalledWith(1, [{ role: 'team_admin', team_id: 1 }]);
    });
});

describe('UsersPage account deletion', () => {
    it('offers the destructive action to a role that holds users.delete', async () => {
        currentUser.current = sessionWith('mandant_admin');
        setUsers([makeUser(1)]);
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        expect(screen.getByRole('button', { name: 'Konto löschen' })).toBeInTheDocument();
    });

    it('hides the destructive action from a role without users.delete', async () => {
        // `team_admin` holds `users.manage` since F4 (2026-10-06) but NOT
        // `users.delete` — backend/config/permissions.php.
        currentUser.current = sessionWith('team_admin');
        setUsers([makeUser(1)]);
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        expect(screen.queryByRole('button', { name: 'Konto löschen' })).not.toBeInTheDocument();
        // The role-assignment action is unaffected: this gate is about deletion.
        expect(screen.getByRole('button', { name: 'Rollen bearbeiten' })).toBeInTheDocument();
    });

    it('hides the destructive action while the session is still unknown', async () => {
        currentUser.current = null;
        setUsers([makeUser(1)]);
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        expect(screen.queryByRole('button', { name: 'Konto löschen' })).not.toBeInTheDocument();
    });

    it('names the account and the application count before deleting', async () => {
        currentUser.current = sessionWith('super_admin');
        setUsers([makeUser(7, { applications: 4, subApplications: 2 })]);
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        await user.click(screen.getByRole('button', { name: 'Konto löschen' }));

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText(/User 7 \(user7@example.test\)/)).toBeInTheDocument();
        expect(within(dialog).getByTestId('confirm-applications')).toHaveTextContent('4 Anträge');
        expect(within(dialog).getByText('2 Sub-Anträge')).toBeInTheDocument();
        expect(deleteUserAccountMock).not.toHaveBeenCalled();
    });

    it('deletes the account and refreshes the list', async () => {
        currentUser.current = sessionWith('super_admin');
        setUsers([makeUser(7, { applications: 1 })]);
        deleteUserAccountMock.mockResolvedValue({
            applications_deleted: 1,
            sub_applications_deleted: 0,
            media_files_deleted: 0,
            role_assignments_deleted: 1,
            sessions_deleted: 0,
            media_files_left_over: [],
        });
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        await user.click(screen.getByRole('button', { name: 'Konto löschen' }));
        await user.click(await screen.findByRole('button', { name: 'Endgültig löschen' }));

        await waitFor(() => expect(deleteUserAccountMock).toHaveBeenCalledWith(7));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        // The row is gone because the list was revalidated, not because the
        // page filtered it locally — a stale list would still show a deleted
        // account until the next reload.
        await waitFor(() => expect(listUsersMock.mock.calls.length).toBeGreaterThan(1));
    });

    it('reports the measured deletion counts on success', async () => {
        currentUser.current = sessionWith('super_admin');
        setUsers([makeUser(7)]);
        deleteUserAccountMock.mockResolvedValue({
            applications_deleted: 3,
            sub_applications_deleted: 1,
            media_files_deleted: 2,
            role_assignments_deleted: 1,
            sessions_deleted: 0,
            media_files_left_over: [],
        });
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        await user.click(screen.getByRole('button', { name: 'Konto löschen' }));
        await user.click(await screen.findByRole('button', { name: 'Endgültig löschen' }));

        const success = await screen.findByText(/Das Konto wurde gelöscht\./);
        expect(success).toHaveTextContent('3 Anträge');
        expect(success).toHaveTextContent('1 Sub-Antrag');
        expect(success).toHaveTextContent('2 Dateien');
    });

    /**
     * A file that survived the deletion is a WARNING, not an error: the
     * account is gone and the backend reports the residue on purpose so a
     * stuck file never turns a completed deletion into a failed request. A
     * `success` alert next to a `warning` alert is the whole contract.
     */
    it('surfaces a surviving media file as a warning, not as an error', async () => {
        currentUser.current = sessionWith('super_admin');
        setUsers([makeUser(7)]);
        deleteUserAccountMock.mockResolvedValue({
            applications_deleted: 0,
            sub_applications_deleted: 0,
            media_files_deleted: 0,
            role_assignments_deleted: 1,
            sessions_deleted: 0,
            media_files_left_over: ['media/1/portrait.png'],
        });
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        await user.click(screen.getByRole('button', { name: 'Konto löschen' }));
        await user.click(await screen.findByRole('button', { name: 'Endgültig löschen' }));

        const warning = await screen.findByText(/Achtung/);
        expect(warning).toHaveTextContent('media/1/portrait.png');
        expect(warning.closest('.alert')).toHaveClass('alert-warning');
        // No error anywhere: the deletion itself succeeded.
        expect(screen.queryByText('Benutzer konnten nicht geladen werden.')).not.toBeInTheDocument();
    });

    it('keeps the dialog open and shows the error when the deletion fails', async () => {
        currentUser.current = sessionWith('super_admin');
        setUsers([makeUser(7)]);
        deleteUserAccountMock.mockRejectedValue(new ApiError(500, 'Datenbank kaputt', {}));
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('1 Benutzer', { exact: true });
        await user.click(screen.getByRole('button', { name: 'Konto löschen' }));
        await user.click(await screen.findByRole('button', { name: 'Endgültig löschen' }));

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByRole('alert')).toHaveTextContent('Datenbank kaputt');
        // Still cancellable, so the user is not trapped in a failed dialog.
        expect(within(dialog).getByRole('button', { name: 'Abbrechen' })).toBeEnabled();
    });
});
