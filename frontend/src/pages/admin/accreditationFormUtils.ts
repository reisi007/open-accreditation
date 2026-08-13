import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { AccreditationPayload } from '../../api/client';
import type { Accreditation } from '../../api/types';

export const createAccreditationSchema = () =>
    z
        .object({
            category_id: z.string().min(1, t`Kategorie ist erforderlich.`),
            scope: z.enum(['event', 'league', 'season']),
            event_id: z.string(),
            team_id: z.string(),
            quota: z.coerce.number().min(1, t`Quota muss mindestens 1 sein.`),
            deadline_start: z.string(),
            deadline_end: z.string(),
            auto_approve: z.boolean(),
            active: z.boolean(),
        })
        .superRefine((values, ctx) => {
            if (values.scope === 'event' && values.event_id === '') {
                ctx.addIssue({
                    code: 'custom',
                    path: ['event_id'],
                    message: t`Bei Geltungsbereich "Spiel" ist ein Event erforderlich.`,
                });
            }

            if (values.deadline_end !== '' && values.deadline_start !== '' && values.deadline_end < values.deadline_start) {
                ctx.addIssue({
                    code: 'custom',
                    path: ['deadline_end'],
                    message: t`Das Ende der Frist muss nach dem Beginn liegen.`,
                });
            }
        });

export type AccreditationFormValues = z.output<ReturnType<typeof createAccreditationSchema>>;
export type AccreditationFormInput = z.input<ReturnType<typeof createAccreditationSchema>>;

export function accreditationFormDefaults(
    initial: Accreditation | null,
    teamAdminTeamId: number | null,
): AccreditationFormValues {
    return {
        category_id: initial?.category_id === undefined ? '' : String(initial.category_id),
        scope: initial?.scope ?? 'event',
        event_id: initial?.event_id === null || initial?.event_id === undefined ? '' : String(initial.event_id),
        team_id:
            initial?.team_id === null || initial?.team_id === undefined
                ? teamAdminTeamId === null
                    ? ''
                    : String(teamAdminTeamId)
                : String(initial.team_id),
        quota: initial?.quota ?? 1,
        deadline_start: initial?.deadline_start ?? '',
        deadline_end: initial?.deadline_end ?? '',
        auto_approve: initial?.auto_approve ?? false,
        active: initial?.active ?? true,
    };
}

export function buildAccreditationPayload(
    values: AccreditationFormValues,
    teamAdminTeamId: number | null,
): AccreditationPayload {
    return {
        category_id: Number(values.category_id),
        scope: values.scope,
        event_id: values.scope === 'event' && values.event_id !== '' ? Number(values.event_id) : null,
        team_id: teamAdminTeamId === null ? (values.team_id === '' ? null : Number(values.team_id)) : teamAdminTeamId,
        quota: values.quota,
        deadline_start: values.deadline_start === '' ? null : values.deadline_start,
        deadline_end: values.deadline_end === '' ? null : values.deadline_end,
        auto_approve: values.auto_approve,
        active: values.active,
    };
}
