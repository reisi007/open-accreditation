import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { SubAccreditationPayload } from '../../api/client';
import type { SubAccreditation } from '../../api/types';

export const createSubAccreditationSchema = () =>
    z
        .object({
            type: z.enum(['park', 'seat']),
            quota: z.coerce.number().min(1, t`Quota muss mindestens 1 sein.`),
            deadline_start: z.string(),
            deadline_end: z.string(),
            auto_approve: z.boolean(),
            active: z.boolean(),
        })
        .superRefine((values, ctx) => {
            if (values.deadline_end !== '' && values.deadline_start !== '' && values.deadline_end < values.deadline_start) {
                ctx.addIssue({
                    code: 'custom',
                    path: ['deadline_end'],
                    message: t`Das Ende der Frist muss nach dem Beginn liegen.`,
                });
            }
        });

export type SubAccreditationFormValues = z.output<ReturnType<typeof createSubAccreditationSchema>>;
export type SubAccreditationFormInput = z.input<ReturnType<typeof createSubAccreditationSchema>>;

export function subAccreditationFormDefaults(initial: SubAccreditation | null): SubAccreditationFormValues {
    return {
        type: initial?.type ?? 'park',
        quota: initial?.quota ?? 1,
        deadline_start: initial?.deadline_start ?? '',
        deadline_end: initial?.deadline_end ?? '',
        auto_approve: initial?.auto_approve ?? false,
        active: initial?.active ?? true,
    };
}

export function buildSubAccreditationPayload(values: SubAccreditationFormValues): SubAccreditationPayload {
    return {
        type: values.type,
        quota: values.quota,
        deadline_start: values.deadline_start === '' ? null : values.deadline_start,
        deadline_end: values.deadline_end === '' ? null : values.deadline_end,
        auto_approve: values.auto_approve,
        active: values.active,
    };
}
