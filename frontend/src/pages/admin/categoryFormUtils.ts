import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { CategoryPayload } from '../../api/client';
import type { Category } from '../../api/types';

export const createCategorySchema = () =>
    z.object({
        name: z.string().min(1, t`Name ist erforderlich.`),
        slug: z
            .string()
            .min(1, t`Slug ist erforderlich.`)
            .regex(
                /^[a-z0-9]+(?:-[a-z0-9]+)*$/,
                t`Slug darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten (z. B. "presse").`,
            ),
        description: z.string(),
        team_id: z.string(),
    });

export type CategoryFormValues = z.infer<ReturnType<typeof createCategorySchema>>;

export function categoryFormDefaults(initial: Category | null): CategoryFormValues {
    return {
        name: initial?.name ?? '',
        slug: initial?.slug ?? '',
        description: initial?.description ?? '',
        team_id: initial?.team_id === null || initial?.team_id === undefined ? '' : String(initial.team_id),
    };
}

export function buildCategoryPayload(values: CategoryFormValues): CategoryPayload {
    return {
        name: values.name,
        slug: values.slug,
        description: values.description.trim() === '' ? null : values.description.trim(),
        team_id: values.team_id === '' ? null : Number(values.team_id),
    };
}
