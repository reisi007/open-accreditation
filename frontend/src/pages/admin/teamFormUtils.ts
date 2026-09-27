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
        /**
         * Reference into the mandant's `venues` list (W12). Empty string = "no
         * home venue" and is translated to `null` by `buildTeamPayload`; the
         * id shape itself is constrained by the combobox, not by zod.
         */
        venue_id: z.string(),
    });

export type TeamFormValues = z.infer<ReturnType<typeof createTeamSchema>>;

export function teamFormDefaults(initial: Team | null): TeamFormValues {
    return {
        name: initial?.name ?? '',
        slug: initial?.slug ?? '',
        venue_id: initial?.venue_id === null || initial?.venue_id === undefined ? '' : String(initial.venue_id),
    };
}

export function buildTeamPayload(values: TeamFormValues): TeamPayload {
    return {
        name: values.name,
        slug: values.slug,
        venue_id: values.venue_id === '' ? null : Number(values.venue_id),
    };
}
