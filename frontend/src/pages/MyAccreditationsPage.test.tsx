import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { SWRConfig } from 'swr';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client';
import type { Application, SubAccreditation, SubApplication } from '../api/types';
import { renderWithProviders } from '../test-setup';
import { MyAccreditationsPage } from './MyAccreditationsPage';

const { listApplicationsMock, listSubApplicationsMock, listSubAccreditationsMock, downloadApplicationWalletMock, downloadSubWalletMock } =
    vi.hoisted(() => ({
        listApplicationsMock: vi.fn(),
        listSubApplicationsMock: vi.fn<() => Promise<SubApplication[]>>(async () => []),
        listSubAccreditationsMock: vi.fn<() => Promise<SubAccreditation[]>>(async () => []),
        downloadApplicationWalletMock: vi.fn(),
        downloadSubWalletMock: vi.fn(),
    }));

vi.mock('../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../api/client')>();
    return {
        ...actual,
        listApplications: listApplicationsMock,
        listSubApplications: listSubApplicationsMock,
        listSubAccreditations: listSubAccreditationsMock,
        downloadApplicationWallet: downloadApplicationWalletMock,
        downloadSubApplicationWallet: downloadSubWalletMock,
    };
});

const approvedApplication = {
    id: 11,
    status: 'approved',
    reason: null,
    created_at: '2026-09-26T10:00:00Z',
    user: { id: 3, name: 'Person', email: 'person@example.test' },
    accreditation: {
        id: 5,
        scope: 'league',
        deadline_end: null,
        category: { id: 1, name: 'Presse', slug: 'presse' },
        event: null,
        team: null,
    },
} as unknown as Application;

function renderPage() {
    return renderWithProviders(
        <SWRConfig value={{ provider: () => new Map() }}>
            <MemoryRouter>
                <MyAccreditationsPage />
            </MemoryRouter>
        </SWRConfig>,
    );
}

beforeEach(() => {
    Object.defineProperty(URL, 'createObjectURL', { value: vi.fn(() => 'blob:mock'), writable: true });
    Object.defineProperty(URL, 'revokeObjectURL', { value: vi.fn(), writable: true });
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
});

afterEach(() => {
    vi.clearAllMocks();
    vi.restoreAllMocks();
});

describe('MyAccreditationsPage wallet downloads', () => {
    it('downloads the wallet pass through the API client instead of a bare <a download>', async () => {
        const user = userEvent.setup();
        listApplicationsMock.mockResolvedValue([approvedApplication]);
        downloadApplicationWalletMock.mockResolvedValue({ blob: new Blob(['x']), filename: 'accreditation-11.pkpass' });
        renderPage();

        const walletGroup = await screen.findByRole('group', { name: 'Wallet-Downloads' });
        // Wallet actions are buttons, not links: a bare <a download> would have
        // saved the JSON error body as `wallet.pkpass` on any non-2xx.
        expect(walletGroup.querySelector('a[download]')).toBeNull();
        await user.click(screen.getByRole('button', { name: 'Apple Wallet' }));

        await waitFor(() => expect(downloadApplicationWalletMock).toHaveBeenCalledWith(11, 'apple'));
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('sends the Google wallet request with the google provider', async () => {
        const user = userEvent.setup();
        listApplicationsMock.mockResolvedValue([approvedApplication]);
        downloadApplicationWalletMock.mockResolvedValue({ blob: new Blob(['{}']), filename: 'wallet.json' });
        renderPage();

        await screen.findByRole('group', { name: 'Wallet-Downloads' });
        await user.click(screen.getByRole('button', { name: 'Google Wallet' }));

        await waitFor(() => expect(downloadApplicationWalletMock).toHaveBeenCalledWith(11, 'google'));
    });

    it('surfaces an ApiError instead of silently downloading the error body', async () => {
        const user = userEvent.setup();
        listApplicationsMock.mockResolvedValue([approvedApplication]);
        downloadApplicationWalletMock.mockRejectedValue(new ApiError(403, 'Kein Wallet-Pass hinterlegt.', {}));
        renderPage();

        await screen.findByRole('group', { name: 'Wallet-Downloads' });
        await user.click(screen.getByRole('button', { name: 'Apple Wallet' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Kein Wallet-Pass hinterlegt.');
    });

    it('falls back to a translated message for a non-ApiError failure', async () => {
        const user = userEvent.setup();
        listApplicationsMock.mockResolvedValue([approvedApplication]);
        downloadApplicationWalletMock.mockRejectedValue(new Error('boom'));
        renderPage();

        await screen.findByRole('group', { name: 'Wallet-Downloads' });
        await user.click(screen.getByRole('button', { name: 'Google Wallet' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('Wallet-Pass konnte nicht heruntergeladen werden.');
    });

    it('surfaces an ApiError for the sub-accreditation wallet too', async () => {
        const user = userEvent.setup();
        const subAccreditation = {
            id: 9,
            accreditation_id: 5,
            type: 'park',
            quota: 3,
            available: 1,
            deadline_start: null,
            deadline_end: null,
            auto_approve: false,
            active: true,
        } as unknown as SubAccreditation;
        const subApplication = {
            id: 21,
            status: 'approved',
            reason: null,
            created_at: '2026-09-26T10:00:00Z',
            sub_accreditation: subAccreditation,
            accreditation: approvedApplication.accreditation,
            user: { id: 3, name: 'Person', email: 'person@example.test' },
        } as unknown as SubApplication;
        listApplicationsMock.mockResolvedValue([approvedApplication]);
        listSubApplicationsMock.mockResolvedValue([subApplication]);
        listSubAccreditationsMock.mockResolvedValue([subAccreditation]);
        downloadSubWalletMock.mockRejectedValue(new ApiError(403, 'Kein Wallet-Pass hinterlegt.', {}));
        renderPage();

        const heading = await screen.findByRole('heading', { name: 'Sub-Akkreditierungen (Park/Sitz)' });
        const subSection = heading.closest('section');
        expect(subSection).not.toBeNull();
        if (subSection === null) return;

        await user.click(within(subSection).getByRole('button', { name: 'Apple Wallet' }));

        await waitFor(() => expect(downloadSubWalletMock).toHaveBeenCalledWith(21));
        const alerts = screen.getAllByRole('alert');
        expect(alerts.some((alert) => alert.textContent?.includes('Kein Wallet-Pass hinterlegt.'))).toBe(true);
    });
});
