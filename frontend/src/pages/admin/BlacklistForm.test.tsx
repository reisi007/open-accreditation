import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../../test-setup';
import { BlacklistForm } from './BlacklistForm';
import type { BlacklistFormValues } from './approvalFormUtils';

const SUBMIT_LABEL = 'Blacklist-Eintrag anlegen';
const SERVER_ERROR = 'E-Mail steht bereits auf der Blacklist.';

function renderForm(
    onSubmit: (values: BlacklistFormValues) => Promise<void>,
    submitError: string | null = null,
) {
    return renderWithProviders(<BlacklistForm submitError={submitError} onSubmit={onSubmit} />);
}

/**
 * Drains the microtask queue twice so Node's rejection tracker has fired for a
 * promise that escaped the submit handler.
 */
async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, 0));
    await new Promise((resolve) => setTimeout(resolve, 0));
}

afterEach(() => {
    vi.clearAllMocks();
});

describe('BlacklistForm validation', () => {
    it('shows the missing email/domain message on the "E-Mail" field and does not submit', async () => {
        const onSubmit = vi.fn<(values: BlacklistFormValues) => Promise<void>>(async () => {});
        const user = userEvent.setup();
        renderForm(onSubmit);

        await user.click(screen.getByRole('button', { name: SUBMIT_LABEL }));

        const message = await screen.findByText('Mindestens E-Mail oder Domäne ist erforderlich.');
        expect(message).toBeInTheDocument();
        expect(screen.getByLabelText('E-Mail').closest('.form-control')).toContainElement(message);
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('shows the message when both fields are whitespace only', async () => {
        const onSubmit = vi.fn<(values: BlacklistFormValues) => Promise<void>>(async () => {});
        const user = userEvent.setup();
        renderForm(onSubmit);

        await user.type(screen.getByLabelText('E-Mail'), '   ');
        await user.type(screen.getByLabelText('Domäne'), '   ');
        await user.click(screen.getByRole('button', { name: SUBMIT_LABEL }));

        expect(await screen.findByText('Mindestens E-Mail oder Domäne ist erforderlich.')).toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('keeps rendering the invalid-address error on the "E-Mail" field', async () => {
        const onSubmit = vi.fn<(values: BlacklistFormValues) => Promise<void>>(async () => {});
        const user = userEvent.setup();
        renderForm(onSubmit);

        await user.type(screen.getByLabelText('E-Mail'), 'not-an-email');
        await user.click(screen.getByRole('button', { name: SUBMIT_LABEL }));

        expect(await screen.findByText('Bitte eine gültige E-Mail-Adresse angeben.')).toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('submits a valid domain and clears the form afterwards', async () => {
        const onSubmit = vi.fn<(values: BlacklistFormValues) => Promise<void>>(async () => {});
        const user = userEvent.setup();
        renderForm(onSubmit);

        await user.type(screen.getByLabelText('Domäne'), 'spam.example');
        await user.type(screen.getByLabelText('Notiz'), 'Bekannt');
        await user.click(screen.getByRole('button', { name: SUBMIT_LABEL }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(onSubmit.mock.calls[0][0]).toEqual({ email: '', domain: 'spam.example', note: 'Bekannt' });
        await waitFor(() => expect(screen.getByLabelText('Domäne')).toHaveValue(''));
        expect(screen.getByLabelText('Notiz')).toHaveValue('');
    });
});

describe('BlacklistForm failed create', () => {
    it('does not leak an unhandled promise rejection and keeps the input', async () => {
        const rejections: unknown[] = [];
        const onUnhandled = (reason: unknown) => {
            rejections.push(reason);
        };
        process.on('unhandledRejection', onUnhandled);

        const onSubmit = vi.fn<(values: BlacklistFormValues) => Promise<void>>(async () => {
            throw new Error(SERVER_ERROR);
        });
        const user = userEvent.setup();

        try {
            renderForm(onSubmit, SERVER_ERROR);
            await user.type(screen.getByLabelText('E-Mail'), 'spam@example.com');
            await user.type(screen.getByLabelText('Notiz'), 'Bekannt');
            await user.click(screen.getByRole('button', { name: SUBMIT_LABEL }));

            await settle();

            // react-hook-form re-throws whatever the submit handler throws and
            // React does not await the submit promise.
            expect(rejections).toEqual([]);
            expect(onSubmit).toHaveBeenCalledTimes(1);
            // The parent's error state stays visible.
            expect(screen.getByRole('alert')).toHaveTextContent(SERVER_ERROR);
            // `reset()` is skipped deliberately on failure, so nothing is lost.
            expect(screen.getByLabelText('E-Mail')).toHaveValue('spam@example.com');
            expect(screen.getByLabelText('Notiz')).toHaveValue('Bekannt');
        } finally {
            process.off('unhandledRejection', onUnhandled);
        }
    });
});
