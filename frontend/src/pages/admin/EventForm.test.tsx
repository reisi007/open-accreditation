import { fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../../test-setup';
import { EventForm } from './EventForm';
import type { EventFormValues } from './eventFormUtils';

// The team select and its venue default come from SWR; the deadline validation
// under test is independent of it, so the hook is stubbed to a "still loading"
// state (no team select, no defaulting effect).
vi.mock('../../logic/useAdminTeams', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../logic/useAdminTeams')>();
    return {
        ...actual,
        useAdminTeams: () => ({ teams: undefined, isLoading: true, error: null, currentTeamIds: [] }),
    };
});

function renderForm(onSubmit: (values: EventFormValues) => Promise<void>) {
    return renderWithProviders(
        <EventForm
            initial={null}
            submitLabel="Event erstellen"
            submitError={null}
            onSubmit={onSubmit}
            onCancel={vi.fn()}
        />,
    );
}

describe('EventForm deadline validation', () => {
    it('shows the deadline-order error on the "Frist Ende" field and does not submit', async () => {
        const onSubmit = vi.fn<(values: EventFormValues) => Promise<void>>(async () => {});
        const user = userEvent.setup();
        renderForm(onSubmit);

        await user.type(screen.getByLabelText('Titel'), 'Heimspiel');
        fireEvent.change(screen.getByLabelText('Frist Beginn'), { target: { value: '2026-08-20' } });
        fireEvent.change(screen.getByLabelText('Frist Ende'), { target: { value: '2026-08-01' } });
        await user.click(screen.getByRole('button', { name: 'Event erstellen' }));

        const message = await screen.findByText('Das Ende der Frist muss nach dem Beginn liegen.');
        expect(message).toBeInTheDocument();
        // The message belongs to the end field, not to a free-floating root error.
        expect(screen.getByLabelText('Frist Ende').closest('.form-control')).toContainElement(message);
        expect(onSubmit).not.toHaveBeenCalled();
        // react-hook-form focuses the first field carrying an error.
        await waitFor(() => expect(screen.getByLabelText('Frist Ende')).toHaveFocus());
    });

    it('submits when the deadline order is valid', async () => {
        const onSubmit = vi.fn<(values: EventFormValues) => Promise<void>>(async () => {});
        const user = userEvent.setup();
        renderForm(onSubmit);

        await user.type(screen.getByLabelText('Titel'), 'Heimspiel');
        fireEvent.change(screen.getByLabelText('Frist Beginn'), { target: { value: '2026-08-01' } });
        fireEvent.change(screen.getByLabelText('Frist Ende'), { target: { value: '2026-08-20' } });
        await user.click(screen.getByRole('button', { name: 'Event erstellen' }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(onSubmit.mock.calls[0][0]).toMatchObject({
            title: 'Heimspiel',
            deadline_start: '2026-08-01',
            deadline_end: '2026-08-20',
        });
        expect(screen.queryByText('Das Ende der Frist muss nach dem Beginn liegen.')).not.toBeInTheDocument();
    });

    it('submits when only one deadline side is filled', async () => {
        const onSubmit = vi.fn<(values: EventFormValues) => Promise<void>>(async () => {});
        const user = userEvent.setup();
        renderForm(onSubmit);

        await user.type(screen.getByLabelText('Titel'), 'Heimspiel');
        fireEvent.change(screen.getByLabelText('Frist Beginn'), { target: { value: '2026-08-20' } });
        await user.click(screen.getByRole('button', { name: 'Event erstellen' }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    });
});
