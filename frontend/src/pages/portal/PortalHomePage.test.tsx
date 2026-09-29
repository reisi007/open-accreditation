import { screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { SWRConfig } from 'swr';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AccountDeletionResult } from '../../api/types';
import { renderWithProviders } from '../../test-setup';
import { ACCOUNT_DELETED_STATE_KEY } from '../../logic/accountDeletedNotice';
import { PortalHomePage } from './PortalHomePage';

const { getPortalEventsMock, getPortalOverviewMock } = vi.hoisted(() => ({
    getPortalOverviewMock: vi.fn(async () => ({
        mandant: {
            id: 1,
            slug: 'hauptseite',
            name: 'Hauptseite',
            logo_url: null,
            header_url: null,
            impressum_text: null,
            privacy_text: null,
            teams_enabled: false,
        },
        teams: [],
    })),
    getPortalEventsMock: vi.fn(async () => []),
}));

vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return { ...actual, getPortalOverview: getPortalOverviewMock, getPortalEvents: getPortalEventsMock };
});

const result: AccountDeletionResult = {
    applications_deleted: 3,
    sub_applications_deleted: 1,
    media_files_deleted: 2,
    role_assignments_deleted: 1,
    sessions_deleted: 0,
    media_files_left_over: [],
};

function renderHome(state: unknown) {
    return renderWithProviders(
        <SWRConfig value={{ provider: () => new Map() }}>
            <MemoryRouter initialEntries={[{ pathname: '/', state }]}>
                <PortalHomePage />
            </MemoryRouter>
        </SWRConfig>,
    );
}

afterEach(() => {
    vi.clearAllMocks();
});

/**
 * The self-service deletion cannot report itself on the page it happened on
 * (the account is gone, so the next `/me` 401s and the guard redirects), so
 * the result is handed over through the router state. These two cases are the
 * contract of that hand-over: an ordinary visit says nothing, and a completed
 * deletion says exactly what went.
 */
describe('PortalHomePage account-deletion notice', () => {
    it('reports nothing on an ordinary visit', () => {
        renderHome(null);

        expect(screen.queryByText(/Dein Konto wurde gelöscht\./)).not.toBeInTheDocument();
    });

    it('reports the measured counts of a completed self-deletion', () => {
        renderHome({ [ACCOUNT_DELETED_STATE_KEY]: result });

        const notice = screen.getByText(/Dein Konto wurde gelöscht\./);
        expect(notice).toHaveTextContent('3 Anträge');
        expect(notice).toHaveTextContent('1 Sub-Antrag');
        expect(notice).toHaveTextContent('2 Dateien');
        expect(notice.closest('.alert')).toHaveClass('alert-success');
    });

    it('raises a warning — not an error — for a file that survived', () => {
        renderHome({
            [ACCOUNT_DELETED_STATE_KEY]: { ...result, media_files_deleted: 1, media_files_left_over: ['media/1/a.png'] },
        });

        const warning = screen.getByText(/Achtung/);
        expect(warning).toHaveTextContent('media/1/a.png');
        expect(warning.closest('.alert')).toHaveClass('alert-warning');
        // The success line stands next to it: the deletion itself worked.
        expect(screen.getByText(/Dein Konto wurde gelöscht\./)).toBeInTheDocument();
    });

    it('ignores a router state that is not a deletion result', () => {
        renderHome({ [ACCOUNT_DELETED_STATE_KEY]: { applications_deleted: 'viele' } });

        expect(screen.queryByText(/Dein Konto wurde gelöscht\./)).not.toBeInTheDocument();
        expect(screen.queryByText(/Achtung/)).not.toBeInTheDocument();
    });
});
