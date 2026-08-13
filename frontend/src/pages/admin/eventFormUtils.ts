import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { EventPayload } from '../../api/client';
import type { Event } from '../../api/types';

export const createEventSchema = () =>
    z
        .object({
            title: z.string().min(1, t`Titel ist erforderlich.`),
            team_id: z.string(),
            date: z.string(),
            venue: z.string(),
            competition: z.string(),
            deadline_start: z.string(),
            deadline_end: z.string(),
            active: z.boolean(),
        })
        .refine(
            (values) =>
                values.deadline_end === '' || values.deadline_start === '' || values.deadline_end >= values.deadline_start,
            t`Das Ende der Frist muss nach dem Beginn liegen.`,
        );

export type EventFormValues = z.infer<ReturnType<typeof createEventSchema>>;

export function eventFormDefaults(initial: Event | null): EventFormValues {
    return {
        title: initial?.title ?? '',
        team_id: initial?.team_id === null || initial?.team_id === undefined ? '' : String(initial.team_id),
        date: initial?.date ?? '',
        venue: initial?.venue ?? '',
        competition: initial?.competition ?? '',
        deadline_start: initial?.deadline_start ?? '',
        deadline_end: initial?.deadline_end ?? '',
        active: initial?.active ?? true,
    };
}

export function buildEventPayload(values: EventFormValues): EventPayload {
    return {
        title: values.title,
        team_id: values.team_id === '' ? null : Number(values.team_id),
        date: values.date === '' ? null : values.date,
        venue: values.venue.trim() === '' ? null : values.venue.trim(),
        competition: values.competition.trim() === '' ? null : values.competition.trim(),
        deadline_start: values.deadline_start === '' ? null : values.deadline_start,
        deadline_end: values.deadline_end === '' ? null : values.deadline_end,
        active: values.active,
    };
}
