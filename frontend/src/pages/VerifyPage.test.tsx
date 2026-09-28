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

    // P13: `/verify` opened directly (broken camera, hand-copied code) showed a
    // bare mandatory input with no hint about where the code comes from.
    it('explains where the code comes from and ties the hint to the input', () => {
        renderAt('/verify');

        const input = screen.getByLabelText('Code');
        const hint = screen.getByText(/Der Code steht als QR-Code auf dem Ausweis/);

        expect(hint).toBeInTheDocument();
        // `aria-describedby` (not just a nearby <p>): the sentence has to be
        // announced WITH the field, otherwise a screen-reader user meets the
        // same bare mandatory input the visual fix was about.
        expect(input).toHaveAttribute('aria-describedby', hint.id);
        expect(hint.id).not.toBe('');
    });

    // Non-vacuity, mutation-verified: breaking the `aria-describedby` link and
    // removing the sentence each turn this red, so a green run here means the
    // hint is really wired to the input and not just rendered somewhere.
    it('has no other element claiming the same description id', () => {
        renderAt('/verify');

        const input = screen.getByLabelText('Code');
        const describedBy = input.getAttribute('aria-describedby');
        expect(describedBy).not.toBeNull();
        if (describedBy === null) return;

        // `aria-describedby` is an IDREF list: a typo'd id would leave the
        // sentence unannounced while the attribute still looks correct.
        expect(document.querySelectorAll(`#${describedBy}`)).toHaveLength(1);
    });
});
