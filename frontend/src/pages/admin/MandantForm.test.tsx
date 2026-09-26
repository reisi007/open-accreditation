import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '../../test-setup';
import type { Mandant } from '../../api/types';
import { MandantForm } from './MandantForm';
import type { MandantFormValues } from './mandantFormUtils';

const mandant: Mandant = {
    id: 7,
    slug: 'verband',
    name: 'Verband',
    logo_url: null,
    header_url: null,
    impressum_text: null,
    privacy_text: null,
    teams_enabled: false,
    is_primary: false,
    is_active: true,
    smtp_has_password: true,
    domains: [],
    teams_count: 0,
    smtp_config: {
        host: 'smtp.example.test',
        port: 587,
        username: 'mailer',
        encryption: 'tls',
    },
};

afterEach(() => {
    vi.restoreAllMocks();
});

describe('MandantForm', () => {
    it('keeps the "SMTP löschen" intent across a FAILED save', async () => {
        const user = userEvent.setup();
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const submissions: boolean[] = [];
        const onSubmit = vi.fn(async (_values: MandantFormValues, smtpCleared: boolean) => {
            submissions.push(smtpCleared);
        });

        const { rerender } = renderWithProviders(
            <MandantForm
                initial={mandant}
                isEdit
                submitLabel="Speichern"
                submitError={null}
                onSubmit={onSubmit}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'SMTP löschen' }));
        expect(screen.getByLabelText('SMTP-Host')).toHaveValue('');

        // First save fails server-side (e.g. duplicate slug → 422). The parent
        // renders the error and resolves; the form must NOT drop the intent.
        rerender(
            <MandantForm
                initial={mandant}
                isEdit
                submitLabel="Speichern"
                submitError="Der Slug ist bereits vergeben."
                onSubmit={onSubmit}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Speichern' }));
        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(submissions).toEqual([true]);

        // The admin fixes the slug and saves again — the delete must still be
        // requested, otherwise the backend keeps the stored config while the
        // form shows empty fields.
        await user.click(screen.getByRole('button', { name: 'Speichern' }));
        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(2));
        expect(submissions).toEqual([true, true]);
    });

    it('drops the intent as soon as an SMTP field is edited again', async () => {
        const user = userEvent.setup();
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const submissions: boolean[] = [];
        const onSubmit = vi.fn(async (_values: MandantFormValues, smtpCleared: boolean) => {
            submissions.push(smtpCleared);
        });

        renderWithProviders(
            <MandantForm
                initial={mandant}
                isEdit
                submitLabel="Speichern"
                submitError={null}
                onSubmit={onSubmit}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'SMTP löschen' }));
        await user.type(screen.getByLabelText('SMTP-Host'), 'smtp2.example.test');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(submissions).toEqual([false]);
    });
});
