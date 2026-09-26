import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../test-setup';
import { Modal } from './Modal';

interface HarnessProps {
    onClose?: () => void;
    dimmed?: boolean;
}

function Harness({ onClose = () => undefined, dimmed }: HarnessProps) {
    const [open, setOpen] = useState(false);

    return (
        <div>
            <button type="button" onClick={() => setOpen(true)}>
                Trigger
            </button>
            {open ? (
                <Modal
                    dimmed={dimmed}
                    onClose={() => {
                        setOpen(false);
                        onClose();
                    }}
                >
                    <button type="button">Erster Dialog-Button</button>
                    <button type="button">Zweiter Dialog-Button</button>
                </Modal>
            ) : null}
        </div>
    );
}

describe('Modal', () => {
    it('exposes real dialog semantics: role=dialog, aria-modal and the open state', async () => {
        const user = userEvent.setup();
        renderWithProviders(<Harness />);

        await user.click(screen.getByRole('button', { name: 'Trigger' }));

        const dialog = screen.getByRole('dialog');
        expect(dialog.tagName).toBe('DIALOG');
        // The `open` content attribute is what daisyUI's `.modal[open]` rule
        // keys on; the previous `modal-open`-only markup exposed no open state
        // at all.
        expect(dialog).toHaveAttribute('open');
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveClass('modal');
        // The old markup additionally needed the CSS-only state class.
        expect(dialog).not.toHaveClass('modal-open');
    });

    it('moves focus into the dialog on open (the trigger alone is not enough)', async () => {
        const user = userEvent.setup();
        renderWithProviders(<Harness />);

        await user.click(screen.getByRole('button', { name: 'Trigger' }));

        await waitFor(() =>
            expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Erster Dialog-Button' })),
        );
    });

    it('closes on Escape and hands focus back to the trigger', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        renderWithProviders(<Harness onClose={onClose} />);

        const trigger = screen.getByRole('button', { name: 'Trigger' });
        await user.click(trigger);
        expect(screen.getByRole('dialog')).toBeInTheDocument();

        await user.keyboard('{Escape}');

        expect(onClose).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await waitFor(() => expect(document.activeElement).toBe(trigger));
    });

    it('closes when the backdrop is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        renderWithProviders(<Harness onClose={onClose} />);

        await user.click(screen.getByRole('button', { name: 'Trigger' }));
        const backdrop = document.querySelector('.modal-backdrop');
        expect(backdrop).not.toBeNull();
        if (backdrop === null) return;

        await user.click(backdrop);

        expect(onClose).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('keeps the backdrop out of the accessibility tree (no focusable close target)', async () => {
        const user = userEvent.setup();
        renderWithProviders(<Harness />);

        await user.click(screen.getByRole('button', { name: 'Trigger' }));

        const backdrop = document.querySelector('.modal-backdrop');
        expect(backdrop).toHaveAttribute('aria-hidden', 'true');
        expect(backdrop?.querySelectorAll('button')).toHaveLength(0);
        // The only buttons inside the dialog are the content ones.
        expect(screen.getAllByRole('button')).toHaveLength(3);
    });

    it('drops daisyUI\'s dark overlay when dimmed is false (stacked modals)', async () => {
        const user = userEvent.setup();
        renderWithProviders(<Harness dimmed={false} />);

        await user.click(screen.getByRole('button', { name: 'Trigger' }));

        const dialog = screen.getByRole('dialog');
        // `bg-transparent!` compiles to `background-color:#0000!important` and
        // therefore wins over daisyUI's `.modal[open]` overlay colour.
        expect(dialog).toHaveClass('bg-transparent!');
    });
});
