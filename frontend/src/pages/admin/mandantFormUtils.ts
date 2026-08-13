import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { MandantPayload } from '../../api/client';
import type { Mandant } from '../../api/types';

export const createMandantSchema = () =>
    z.object({
        name: z.string().min(1, t`Name ist erforderlich.`),
        slug: z
            .string()
            .min(1, t`Slug ist erforderlich.`)
            .regex(
                /^[a-z0-9]+(?:-[a-z0-9]+)*$/,
                t`Slug darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten (z. B. "mein-verband").`,
            ),
        teams_enabled: z.boolean(),
        is_active: z.boolean(),
        impressum_text: z.string(),
        privacy_text: z.string(),
        smtp_host: z.string(),
        smtp_port: z.string().refine(
            (value) => value === '' || (/^\d+$/.test(value) && Number(value) >= 1 && Number(value) <= 65535),
            t`Bitte eine gültige Portnummer angeben.`,
        ),
        smtp_username: z.string(),
        smtp_password: z.string(),
        smtp_encryption: z.string(),
    });

export type MandantFormValues = z.infer<ReturnType<typeof createMandantSchema>>;

export interface MandantFormBuildOptions {
    /** Explicitly clear the stored SMTP config (payload `smtp_config: null`). */
    clearSmtp?: boolean;
}

export function mandantFormDefaults(initial: Mandant | null): MandantFormValues {
    return {
        name: initial?.name ?? '',
        slug: initial?.slug ?? '',
        teams_enabled: initial?.teams_enabled ?? false,
        is_active: initial?.is_active ?? true,
        impressum_text: initial?.impressum_text ?? '',
        privacy_text: initial?.privacy_text ?? '',
        smtp_host: initial?.smtp_config?.host ?? '',
        smtp_port: initial?.smtp_config?.port === null ? '' : String(initial?.smtp_config?.port ?? ''),
        smtp_username: initial?.smtp_config?.username ?? '',
        smtp_password: '',
        smtp_encryption: initial?.smtp_config?.encryption ?? '',
    };
}

export function buildMandantPayload(values: MandantFormValues, options: MandantFormBuildOptions = {}): MandantPayload {
    const payload: MandantPayload = {
        name: values.name,
        slug: values.slug,
        teams_enabled: values.teams_enabled,
        is_active: values.is_active,
        impressum_text: values.impressum_text,
        privacy_text: values.privacy_text,
    };

    if (options.clearSmtp) {
        payload.smtp_config = null;
        return payload;
    }

    const hasSmtp =
        values.smtp_host.trim() !== '' ||
        values.smtp_port.trim() !== '' ||
        values.smtp_username.trim() !== '';

    if (hasSmtp) {
        const config: NonNullable<MandantPayload['smtp_config']> = {
            host: values.smtp_host.trim() || null,
            port: values.smtp_port.trim() === '' ? null : Number(values.smtp_port),
            username: values.smtp_username.trim() || null,
            encryption: values.smtp_encryption === '' ? null : values.smtp_encryption,
        };
        // The `password` key is only sent when the user typed a new value.
        // Omitting it lets the backend keep the stored password (edit mode).
        if (values.smtp_password.trim() !== '') {
            config.password = values.smtp_password;
        }
        payload.smtp_config = config;
    }

    return payload;
}
