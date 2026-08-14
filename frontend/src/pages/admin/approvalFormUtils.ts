import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { AllocationPayload, BlacklistPayload } from '../../api/client';
import type { ApplicationAction } from '../../api/types';

export interface ApplicationActionInput {
    status?: 'approved' | 'denied';
    reason?: string;
    priority?: boolean;
}

/**
 * Build the PUT body for a single admin action (approve / deny+reason /
 * priority). Undefined and empty-string fields are omitted so the backend
 * validation stays authoritative.
 */
export function buildApplicationAction(action: ApplicationActionInput): ApplicationAction {
    return {
        ...(action.status !== undefined ? { status: action.status } : {}),
        ...(action.reason !== undefined && action.reason.trim() !== '' ? { reason: action.reason } : {}),
        ...(action.priority !== undefined ? { priority: action.priority } : {}),
    };
}

export const createBlacklistSchema = () =>
    z
        .object({
            email: z.string().optional(),
            domain: z.string().optional(),
            note: z.string().optional(),
        })
        .superRefine((values, ctx) => {
            const email = (values.email ?? '').trim();
            const domain = (values.domain ?? '').trim();

            if (email === '' && domain === '') {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: t`Mindestens E-Mail oder Domäne ist erforderlich.`,
                });
            }

            if (email !== '' && !z.string().email().safeParse(email).success) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    path: ['email'],
                    message: t`Bitte eine gültige E-Mail-Adresse angeben.`,
                });
            }
        });

export type BlacklistFormValues = z.infer<ReturnType<typeof createBlacklistSchema>>;

export function buildBlacklistPayload(values: BlacklistFormValues): BlacklistPayload {
    const email = (values.email ?? '').trim();
    const domain = (values.domain ?? '').trim();
    const note = (values.note ?? '').trim();

    return {
        ...(email !== '' ? { email } : {}),
        ...(domain !== '' ? { domain } : {}),
        ...(note !== '' ? { note } : {}),
    };
}

export function buildAllocationPayload(mode: 'all' | 'first', limit?: number): AllocationPayload {
    return mode === 'first' && limit !== undefined ? { mode, limit } : { mode };
}
