import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { Venue } from '../../api/types';

/**
 * Name schema for the venue master data (W12). A venue is created MANUALLY —
 * there is no slug, the name IS the identity, and duplicates are rejected by
 * the backend (422) because a deactivated name must not become re-creatable.
 */
export const createVenueSchema = () =>
    z.object({
        name: z
            .string()
            .min(1, t`Name ist erforderlich.`)
            .max(120, t`Name darf höchstens 120 Zeichen lang sein.`),
    });

export type VenueFormValues = z.infer<ReturnType<typeof createVenueSchema>>;

export function venueFormDefaults(initial: Venue | null): VenueFormValues {
    return {
        name: initial?.name ?? '',
    };
}
