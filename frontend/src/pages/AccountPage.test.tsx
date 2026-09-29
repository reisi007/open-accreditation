import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { SWRConfig } from 'swr';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client';
import type { AccountSummary } from '../api/types';
import { renderWithProviders } from '../test-setup';
import { ACCOUNT_DELETED_STATE_KEY } from '../logic/accountDeletedNotice';
import { AccountPage } from './AccountPage';

const { deleteOwnAccountMock, getAccountMock, logoutMock } = vi.hoisted(() => ({
    getAccountMock: vi.fn(),
    deleteOwnAccountMock: vi.fn(),
    logoutMock: vi.fn(async () => undefined),
}));

vi.mock('../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../api/client')>();
    return { ...actual, getAccount: getAccountMock, deleteOwnAccount: deleteOwnAccountMock };
});

vi.mock('../logic/useAuth', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../logic/useAuth')>();
    return { ...actual, useAuth: () => ({ user: null, isAuthenticated: true, isLoading: false, logout: logoutMock }) };
});

const account: AccountSummary = {
    id: 12,
    name: 'Max Mustermann',
    email: 'max@example.test',
    mandant_id: 1,
    mandant_name: 'Verband A',
    applications_count: 3,
    sub_applications_count: 1,
    media_count: 2,
};

const deletionResult = {
    applications_deleted: 3,
    sub_applications_deleted: 1,
    media_files_deleted: 2,
    role_assignments_deleted: 1,
    sessions_deleted: 0,
    media_files_left_over: [] as string[],
};

/** The start page stands in for the real one: it only has to SHOW the state. */
function StartPage() {
    return <p data-testid="start-page">Startseite</p>;
}

/**
 * The router state is the hand-over channel that lets the deletion result
 * survive the navigation; asserting on it is the only way to prove the result
 * is actually carried over rather than dropped on the floor.
 */
function CarriedState() {
    const state = useLocation().state as Record<string, unknown> | null;

    return <span data-testid="carried-state">{JSON.stringify(state)}</span>;
}

function renderPage() {
    return renderWithProviders(
        <SWRConfig value={{ provider: () => new Map() }}>
            <MemoryRouter initialEntries={['/konto']}>
                <Routes>
                    <Route path="/konto" element={<AccountPage />} />
                    <Route
                        path="/"
                        element={
                            <>
                                <StartPage />
                                <CarriedState />
                            </>
                        }
                    />
                </Routes>
            </MemoryRouter>
        </SWRConfig>,
    );
}

afterEach(() => {
    vi.clearAllMocks();
});

describe('AccountPage', () => {
    it('shows the account identity and the counts a deletion would take', async () => {
        getAccountMock.mockResolvedValue(account);
        renderPage();

        expect(await screen.findByText('Max Mustermann')).toBeInTheDocument();
        expect(screen.getByText('max@example.test')).toBeInTheDocument();
        expect(screen.getByText('Verband A')).toBeInTheDocument();
        // The application count is the number the confirmation must name.
        expect(screen.getByText('3 Anträge')).toBeInTheDocument();
        expect(screen.getByText('1 Sub-Antrag')).toBeInTheDocument();
        expect(screen.getByText('2 Dateien')).toBeInTheDocument();
    });

    it('reports a failed load instead of rendering an empty account', async () => {
        getAccountMock.mockRejectedValue(new ApiError(500, 'Boom', {}));
        renderPage();

        expect(await screen.findByRole('alert')).toHaveTextContent('Konto konnte nicht geladen werden.');
        expect(screen.queryByRole('button', { name: 'Konto löschen' })).not.toBeInTheDocument();
    });

    it('names the account and the application count in the confirmation', async () => {
        getAccountMock.mockResolvedValue(account);
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('Max Mustermann');
        await user.click(screen.getByRole('button', { name: 'Konto löschen' }));

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByText(/Max Mustermann \(max@example.test\)/)).toBeInTheDocument();
        // MEASURED requirement, not a nicety: the confirmation names the number
        // of applications, and the test asserts the rendered NUMBER.
        expect(within(dialog).getByTestId('confirm-applications')).toHaveTextContent('3 Anträge');
        expect(within(dialog).getByText('Die Löschung ist endgültig und kann nicht rückgängig gemacht werden.')).toBeInTheDocument();
        // Nothing is deleted by merely asking.
        expect(deleteOwnAccountMock).not.toHaveBeenCalled();
    });

    it('cancels without deleting', async () => {
        getAccountMock.mockResolvedValue(account);
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('Max Mustermann');
        await user.click(screen.getByRole('button', { name: 'Konto löschen' }));
        await screen.findByRole('dialog');
        await user.click(screen.getByRole('button', { name: 'Abbrechen' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(deleteOwnAccountMock).not.toHaveBeenCalled();
    });

    it('deletes the account, tears the session down and carries the result to the start page', async () => {
        getAccountMock.mockResolvedValue(account);
        deleteOwnAccountMock.mockResolvedValue(deletionResult);
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('Max Mustermann');
        await user.click(screen.getByRole('button', { name: 'Konto löschen' }));
        await user.click(await screen.findByRole('button', { name: 'Endgültig löschen' }));

        await waitFor(() => expect(deleteOwnAccountMock).toHaveBeenCalledTimes(1));
        // The account no longer exists, so the session MUST be torn down —
        // otherwise the shell keeps rendering a session the backend refuses.
        await waitFor(() => expect(logoutMock).toHaveBeenCalledTimes(1));
        expect(await screen.findByTestId('start-page')).toBeInTheDocument();

        const carried = JSON.parse(screen.getByTestId('carried-state').textContent ?? 'null') as Record<string, unknown>;
        expect(carried[ACCOUNT_DELETED_STATE_KEY]).toEqual(deletionResult);
    });

    it('keeps the account and the session when the deletion fails', async () => {
        getAccountMock.mockResolvedValue(account);
        deleteOwnAccountMock.mockRejectedValue(new ApiError(500, 'Datenbank kaputt', {}));
        const user = userEvent.setup();
        renderPage();

        await screen.findByText('Max Mustermann');
        await user.click(screen.getByRole('button', { name: 'Konto löschen' }));
        await user.click(await screen.findByRole('button', { name: 'Endgültig löschen' }));

        const dialog = await screen.findByRole('dialog');
        expect(within(dialog).getByRole('alert')).toHaveTextContent('Datenbank kaputt');
        // A failed deletion must NOT log the user out — the account is still there.
        expect(logoutMock).not.toHaveBeenCalled();
    });
});
