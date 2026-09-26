import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../test-setup';
import { MediaField } from './MediaField';

afterEach(() => {
    vi.restoreAllMocks();
});

function renderField() {
    const onUpload = vi.fn(async () => undefined);
    const onDelete = vi.fn(async () => undefined);
    renderWithProviders(<MediaField label="Logo" url={null} onUpload={onUpload} onDelete={onDelete} />);

    return { onUpload, onDelete };
}

describe('MediaField', () => {
    it('clears the native file input so the same file can be re-selected after an upload', async () => {
        const user = userEvent.setup();
        const { onUpload } = renderField();
        const input = screen.getByLabelText('Logo');
        const file = new File(['x'], 'logo.png', { type: 'image/png' });

        await user.upload(input, file);
        expect(input).toHaveValue('');
        expect(screen.getByText('logo.png')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Hochladen' })).toBeEnabled();

        await user.click(screen.getByRole('button', { name: 'Hochladen' }));

        expect(onUpload).toHaveBeenCalledTimes(1);
        await waitFor(() => expect(screen.queryByText('logo.png')).not.toBeInTheDocument());
        expect(screen.getByRole('button', { name: 'Hochladen' })).toBeDisabled();

        // Re-selecting the very same file must work again. Both the browser and
        // userEvent suppress the `change` event while the native selection is
        // unchanged, so this only passes when the input's value was reset.
        await user.upload(input, file);
        expect(screen.getByText('logo.png')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Hochladen' })).toBeEnabled();
    });

    it('keeps the selection and surfaces the error when the upload fails', async () => {
        const user = userEvent.setup();
        const onUpload = vi.fn(async () => {
            throw new Error('boom');
        });
        renderWithProviders(<MediaField label="Logo" url={null} onUpload={onUpload} onDelete={vi.fn()} />);
        const input = screen.getByLabelText('Logo');

        await user.upload(input, new File(['x'], 'logo.png', { type: 'image/png' }));
        await user.click(screen.getByRole('button', { name: 'Hochladen' }));

        expect(await screen.findByText('Upload fehlgeschlagen.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Hochladen' })).toBeEnabled();
    });
});
