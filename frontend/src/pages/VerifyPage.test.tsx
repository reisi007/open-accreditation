import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useNavigate } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../test-setup';
import { VerifyPage } from './VerifyPage';

const { verifyTokenMock } = vi.hoisted(() => ({ verifyTokenMock: vi.fn() }));

vi.mock('../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../api/client')>();
    return { ...actual, verifyToken: verifyTokenMock };
});

function TokenSwitch() {
    const navigate = useNavigate();

    return (
        <button type="button" onClick={() => navigate('/verify/token-b')}>
            zweiter Token
        </button>
    );
}

function renderAt(path: string) {
    return renderWithProviders(
        <MemoryRouter initialEntries={[path]}>
            <TokenSwitch />
            <Routes>
                <Route path="/verify" element={<VerifyPage />} />
                <Route path="/verify/:token" element={<VerifyPage />} />
            </Routes>
        </MemoryRouter>,
    );
}

afterEach(() => {
    vi.clearAllMocks();
});

describe('VerifyPage', () => {
    it('verifies the token from the URL on load', async () => {
        verifyTokenMock.mockResolvedValue({ status: 'approved', name: 'Person A', category: 'Presse' });
        renderAt('/verify/token-a');

        await waitFor(() => expect(verifyTokenMock).toHaveBeenCalledWith('token-a'));
        expect(await screen.findByText('Person A')).toBeInTheDocument();
    });

    it('re-syncs the token when the URL changes under the mounted component', async () => {
        // `verify` and `verify/:token` render the SAME component, so a SPA
        // back/forward between two scanned QR links keeps the instance alive.
        verifyTokenMock.mockImplementation(async (token: string) =>
            token === 'token-a'
                ? { status: 'approved', name: 'Person A', category: 'Presse' }
                : { status: 'approved', name: 'Person B', category: 'Ordnung' },
        );
        const user = userEvent.setup();
        renderAt('/verify/token-a');

        expect(await screen.findByText('Person A')).toBeInTheDocument();
        expect(screen.getByLabelText('Code')).toHaveValue('token-a');

        await user.click(screen.getByRole('button', { name: 'zweiter Token' }));

        await waitFor(() => expect(verifyTokenMock).toHaveBeenCalledWith('token-b'));
        expect(await screen.findByText('Person B')).toBeInTheDocument();
        // The previous person's result must not linger under the new URL.
        expect(screen.queryByText('Person A')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Code')).toHaveValue('token-b');
    });

    it('still verifies a manually typed token on /verify', async () => {
        verifyTokenMock.mockResolvedValue({ status: 'denied', name: 'Person C' });
        const user = userEvent.setup();
        renderAt('/verify');

        await user.type(screen.getByLabelText('Code'), 'token-x');
        await user.click(screen.getByRole('button', { name: 'Prüfen' }));

        await waitFor(() => expect(verifyTokenMock).toHaveBeenCalledWith('token-x'));
        expect(await screen.findByText('Abgelehnt')).toBeInTheDocument();
    });
});
