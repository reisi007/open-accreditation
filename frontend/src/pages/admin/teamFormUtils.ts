import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { TeamPayload } from '../../api/client';
import type { Team } from '../../api/types';

export const createTeamSchema = () =>
    z.object({
        name: z.string().min(1, t`Name ist erforderlich.`),
        slug: z
            .string()
            .min(1, t`Slug ist erforderlich.`)
            .regex(
                /^[a-z0-9]+(?:-[a-z0-9]+)*$/,
                t`Slug darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten (z. B. "mein-verein").`,
            ),
        home_venue: z.string(),
    });

export type TeamFormValues = z.infer<ReturnType<typeof createTeamSchema>>;

export function teamFormDefaults(initial: Team | null): TeamFormValues {
    return {
        name: initial?.name ?? '',
        slug: initial?.slug ?? '',
        home_venue: initial?.home_venue ?? '',
    };
}

export function buildTeamPayload(values: TeamFormValues): TeamPayload {
    return {
        name: values.name,
        slug: values.slug,
        home_venue: values.home_venue.trim() === '' ? null : values.home_venue.trim(),
    };
}
