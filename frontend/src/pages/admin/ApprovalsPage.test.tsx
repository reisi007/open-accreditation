import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { useEffect } from 'react';
import { SWRConfig, useSWRConfig, type ScopedMutator } from 'swr';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/client';
import type { AdminApplication, AdminSubApplication, Blacklist } from '../../api/types';
import { renderWithProviders } from '../../test-setup';
import { ApprovalsPage } from './ApprovalsPage';

const {
    serverApps,
    serverSubApps,
    serverBlacklists,
    listAdminApplicationsMock,
    listAdminSubApplicationsMock,
    listBlacklistsMock,
    updateAdminApplicationMock,
    resendSubApplicationMailMock,
} = vi.hoisted(() => {
    const serverApps: AdminApplication[] = [];
    const serverSubApps: AdminSubApplication[] = [];
    const serverBlacklists: Blacklist[] = [];
    return {
        serverApps,
        serverSubApps,
        serverBlacklists,
        // A fresh array/object per call: SWR compares the new payload with the
        // cached one by reference first, so returning the same array would make
        // it treat every "revalidation" as unchanged.
        listAdminApplicationsMock: vi.fn(async () => serverApps.map((row) => ({ ...row }))),
        listAdminSubApplicationsMock: vi.fn(async () => serverSubApps.map((row) => ({ ...row }))),
        listBlacklistsMock: vi.fn(async () => serverBlacklists.map((row) => ({ ...row }))),
        updateAdminApplicationMock: vi.fn(),
        resendSubApplicationMailMock: vi.fn(async () => 'E-Mail wurde erneut in die Warteschlange gestellt.'),
    };
});

vi.mock('../../api/client', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../api/client')>();
    return {
        ...actual,
        listAdminAccreditations: vi.fn(async () => []),
        listAdminApplicationMedia: vi.fn(async () => []),
        listAdminApplications: listAdminApplicationsMock,
        listAdminSubApplications: listAdminSubApplicationsMock,
        listAllAdminSubAccreditations: vi.fn(async () => []),
        listBadgeTemplates: vi.fn(async () => []),
        listBlacklists: listBlacklistsMock,
        resendApplicationMail: vi.fn(async () => undefined),
        resendSubApplicationMail: resendSubApplicationMailMock,
        allocateAccreditation: vi.fn(),
        exportBadges: vi.fn(),
        createBlacklist: vi.fn(),
        deleteBlacklist: vi.fn(),
        updateAdminApplication: updateAdminApplicationMock,
        updateAdminSubApplication: vi.fn(),
    };
});

function makeApplication(id: number, priority: boolean): AdminApplication {
    return {
        id,
        status: 'requested',
        priority,
        reason: null,
        created_at: '2026-09-26T10:00:00Z',
        user: { id, name: `Person ${id}`, email: `p${id}@example.test` },
        accreditation: {
            id: 5,
            scope: 'league',
            deadline_end: null,
            category: { id: 1, name: 'Presse', slug: 'presse' },
            event: null,
            team: null,
        },
    } as unknown as AdminApplication;
}

function makeSubApplication(id: number): AdminSubApplication {
    return {
        id,
        status: 'requested',
        priority: false,
        reason: null,
        created_at: '2026-09-26T10:00:00Z',
        user: { id, name: `Person ${id}`, email: `p${id}@example.test` },
        sub_accreditation: { id: 9, type: 'park', quota: 3, available: 1, deadline_end: null },
        accreditation: {
            id: 5,
            scope: 'league',
            deadline_end: null,
            category: { id: 1, name: 'Presse', slug: 'presse' },
            event: null,
            team: null,
        },
    } as unknown as AdminSubApplication;
}

/** `makeSubApplication` with a decided status — the only two the backend can mail. */
function makeDecidedSubApplication(id: number, status: 'approved' | 'denied'): AdminSubApplication {
    return { ...makeSubApplication(id), status, reason: status === 'denied' ? 'Zu viele Anträge.' : null };
}

function makeBlacklist(id: number, email: string): Blacklist {
    return {
        id,
        email,
        domain: null,
        note: null,
        created_at: '2026-09-26T10:00:00Z',
    } as unknown as Blacklist;
}

/**
 * Hands the SWR-bound `mutate` of the isolated test cache to the test so it can
 * revalidate the EXACT application key. Changing a filter would change the key
 * too, which empties the table for a frame and remounts the rows — that would
 * mask the stale-state bug instead of pinning it.
 */
function MutateBridge({ onReady }: { onReady: (mutate: ScopedMutator) => void }) {
    const { mutate } = useSWRConfig();

    useEffect(() => {
        onReady(mutate);
    }, [mutate, onReady]);

    return null;
}

function renderPage() {
    const bridge: { mutate: ScopedMutator | null } = { mutate: null };

    const utils = renderWithProviders(
        <SWRConfig value={{ provider: () => new Map() }}>
            <MemoryRouter>
                <MutateBridge onReady={(mutate) => (bridge.mutate = mutate)} />
                <ApprovalsPage />
            </MemoryRouter>
        </SWRConfig>,
    );

    return {
        ...utils,
        revalidate: async (...keys: Parameters<ScopedMutator>[0][]) => {
            for (const key of keys) {
                await bridge.mutate?.(key);
            }
        },
    };
}

function reset(...collections: unknown[][]) {
    for (const collection of collections) {
        collection.length = 0;
    }
}

afterEach(() => {
    vi.clearAllMocks();
    reset(serverApps, serverSubApps, serverBlacklists);
});

describe('ApprovalsPage result counters (ICU plurals)', () => {
    it('renders the singular and plural German form of the Anträge counter', async () => {
        serverApps.push(makeApplication(1, false));
        const { revalidate } = renderPage();

        expect(await screen.findByText('1 Antrag', { exact: true })).toBeInTheDocument();

        serverApps.push(makeApplication(2, false));
        await revalidate(['/api/admin/applications', '', '']);

        expect(await screen.findByText('2 Anträge', { exact: true })).toBeInTheDocument();
        expect(screen.queryByText('2 Antrag', { exact: true })).not.toBeInTheDocument();
    });

    it('renders the singular and plural German form of the Sub-Anträge counter', async () => {
        const user = userEvent.setup();
        serverSubApps.push(makeSubApplication(1));
        const { revalidate } = renderPage();

        await user.click(screen.getByRole('tab', { name: 'Sub-Anträge' }));
        expect(await screen.findByText('1 Sub-Antrag', { exact: true })).toBeInTheDocument();

        serverSubApps.push(makeSubApplication(2));
        await revalidate(['/api/admin/sub-applications', '', '']);

        expect(await screen.findByText('2 Sub-Anträge', { exact: true })).toBeInTheDocument();
    });

    it('renders the singular, plural and zero forms of the Blacklist counter', async () => {
        const user = userEvent.setup();
        serverBlacklists.push(makeBlacklist(1, 'a@example.test'));
        const { revalidate } = renderPage();

        await user.click(screen.getByRole('tab', { name: 'Blacklist' }));
        expect(await screen.findByText('1 Eintrag', { exact: true })).toBeInTheDocument();

        serverBlacklists.push(makeBlacklist(2, 'b@example.test'));
        await revalidate(['/api/admin/blacklists', '']);
        expect(await screen.findByText('2 Einträge', { exact: true })).toBeInTheDocument();

        serverBlacklists.length = 0;
        await revalidate(['/api/admin/blacklists', '']);
        // ICU `other` covers zero: "0 Einträge", not "0 Eintrag".
        expect(await screen.findByText('0 Einträge', { exact: true })).toBeInTheDocument();
    });
});

describe('ApprovalsPage VIP toggle', () => {
    it('re-syncs the optimistic VIP state when the server value changes underneath', async () => {
        const user = userEvent.setup();
        serverApps.push(makeApplication(1, false));
        // A cooperating backend: the write lands and the revalidation confirms it.
        updateAdminApplicationMock.mockImplementation(async (id: number, payload: { priority?: boolean }) => {
            const row = serverApps.find((app) => app.id === id);
            if (row !== undefined && payload.priority !== undefined) {
                row.priority = payload.priority;
            }
        });
        const { revalidate } = renderPage();

        await waitFor(() => expect(screen.getByLabelText('VIP')).not.toBeChecked());

        await user.click(screen.getByLabelText('VIP'));
        await waitFor(() => expect(screen.getByLabelText('VIP')).toBeChecked());

        // A bulk action / second tab revokes the VIP server-side and the next
        // revalidation must win over the local optimistic value. The rows are
        // keyed by the stable application id, so `useState(application.priority)`
        // alone would keep rendering the stale local `true` forever.
        serverApps[0].priority = false;
        await revalidate(['/api/admin/applications', '', '']);

        await waitFor(() => expect(screen.getByLabelText('VIP')).not.toBeChecked());
    });

    it('reverts the optimistic VIP state when the write fails', async () => {
        const user = userEvent.setup();
        serverApps.push(makeApplication(1, false));
        updateAdminApplicationMock.mockRejectedValue(new Error('boom'));
        renderPage();

        const row = await screen.findByRole('row', { name: /p1@example\.test/ });
        const vip = within(row).getByLabelText('VIP');
        await waitFor(() => expect(vip).not.toBeChecked());

        await user.click(vip);

        expect(await within(row).findByText('VIP-Status konnte nicht geändert werden.')).toBeInTheDocument();
        expect(within(row).getByLabelText('VIP')).not.toBeChecked();
    });
});

/**
 * The sub-row resend button (Position 47): the Park-/Sitzkarte counterpart of
 * the main-request one, on the SAME surface the admin approves/denies a
 * sub-application.
 *
 * The success text is the SERVER's, not ours — `AdminSubApplicationController::resend`
 * only dispatches a `SendMandantMail` job, so "wurde erneut gesendet" would be a
 * claim about a relay this process never talked to (see
 * `logic/serverActionMessage.ts`).
 */
describe('ApprovalsPage sub-application resend', () => {
    async function openSubTab(subApps: AdminSubApplication[]) {
        const user = userEvent.setup();
        serverSubApps.push(...subApps);
        renderPage();
        await user.click(screen.getByRole('tab', { name: 'Sub-Anträge' }));
        return user;
    }

    it('offers no resend button on a requested sub-application', async () => {
        // MEASURED: the backend mails `approved` and `denied` only and answers
        // 422 for anything else (`AdminSubApplicationController::resend:186-210`).
        // A button there could only ever fail.
        await openSubTab([makeSubApplication(1)]);
        const row = await screen.findByRole('row', { name: /p1@example\.test/ });

        expect(within(row).queryByRole('button', { name: 'E-Mail erneut senden' })).not.toBeInTheDocument();
        expect(resendSubApplicationMailMock).not.toHaveBeenCalled();
    });

    it('resends the approved sub-application mail and shows the SERVER message', async () => {
        const user = await openSubTab([makeDecidedSubApplication(1, 'approved')]);
        const row = await screen.findByRole('row', { name: /p1@example\.test/ });

        await user.click(within(row).getByRole('button', { name: 'E-Mail erneut senden' }));

        expect(resendSubApplicationMailMock).toHaveBeenCalledWith(1);
        const status = await within(row).findByRole('status');
        expect(status).toHaveTextContent('E-Mail wurde erneut in die Warteschlange gestellt.');
        // The invented wording must be GONE, not merely joined by the right one:
        // a UI that showed both would still assert delivery it cannot know.
        expect(status).not.toHaveTextContent('erneut gesendet.');
    });

    it('resends a denied sub-application mail as well', async () => {
        const user = await openSubTab([makeDecidedSubApplication(2, 'denied')]);
        const row = await screen.findByRole('row', { name: /p2@example\.test/ });

        await user.click(within(row).getByRole('button', { name: 'E-Mail erneut senden' }));

        expect(resendSubApplicationMailMock).toHaveBeenCalledWith(2);
        expect(await within(row).findByRole('status')).toHaveTextContent(
            'E-Mail wurde erneut in die Warteschlange gestellt.',
        );
    });

    it('shows the fallback for a 200 whose body carried no message', async () => {
        // The one case where the UI does invent text — and it says what is true:
        // the job was ACCEPTED, nothing about the relay.
        resendSubApplicationMailMock.mockResolvedValueOnce('');
        const user = await openSubTab([makeDecidedSubApplication(1, 'approved')]);
        const row = await screen.findByRole('row', { name: /p1@example\.test/ });

        await user.click(within(row).getByRole('button', { name: 'E-Mail erneut senden' }));

        expect(await within(row).findByRole('status')).toHaveTextContent('Zustellauftrag angenommen.');
    });

    it('surfaces a 422 as the SUB-application message', async () => {
        resendSubApplicationMailMock.mockRejectedValueOnce(
            new ApiError(422, 'Sub-application has no mailable status.', {}),
        );
        const user = await openSubTab([makeDecidedSubApplication(1, 'approved')]);
        const row = await screen.findByRole('row', { name: /p1@example\.test/ });

        await user.click(within(row).getByRole('button', { name: 'E-Mail erneut senden' }));

        expect(await within(row).findByRole('alert')).toHaveTextContent(
            'Für diesen Sub-Antrag kann keine E-Mail gesendet werden.',
        );
        expect(within(row).queryByRole('status')).not.toBeInTheDocument();
    });

    it('surfaces a 403 as the SUB-application permission message', async () => {
        resendSubApplicationMailMock.mockRejectedValueOnce(new ApiError(403, 'This action is unauthorized.', {}));
        const user = await openSubTab([makeDecidedSubApplication(1, 'approved')]);
        const row = await screen.findByRole('row', { name: /p1@example\.test/ });

        await user.click(within(row).getByRole('button', { name: 'E-Mail erneut senden' }));

        expect(await within(row).findByRole('alert')).toHaveTextContent('Keine Berechtigung für diesen Sub-Antrag.');
    });

    it('surfaces a 404 (foreign mandant) with the server message, unmapped', async () => {
        resendSubApplicationMailMock.mockRejectedValueOnce(
            new ApiError(404, 'No query results for model [App\\Models\\SubApplication] 999.', {}),
        );
        const user = await openSubTab([makeDecidedSubApplication(1, 'approved')]);
        const row = await screen.findByRole('row', { name: /p1@example\.test/ });

        await user.click(within(row).getByRole('button', { name: 'E-Mail erneut senden' }));

        expect(await within(row).findByRole('alert')).toHaveTextContent(
            'No query results for model [App\\Models\\SubApplication] 999.',
        );
    });
});
