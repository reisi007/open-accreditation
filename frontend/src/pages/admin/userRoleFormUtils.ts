import { t } from '@lingui/core/macro';
import { z } from 'zod';
import type { UserRoleInput } from '../../api/client';
import type { AdminUser } from '../../api/types';

export interface RoleFormValues {
    mandant_admin: boolean;
    team_admin: boolean;
    user: boolean;
    verifier: boolean;
    team_id: string;
}

export const createRoleSchema = () =>
    z
        .object({
            mandant_admin: z.boolean(),
            team_admin: z.boolean(),
            user: z.boolean(),
            verifier: z.boolean(),
            team_id: z.string(),
        })
        .superRefine((values, ctx) => {
            if (!values.mandant_admin && !values.team_admin && !values.user && !values.verifier) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    message: t`Mindestens eine Rolle muss ausgewählt bleiben.`,
                });
            }
            if (values.team_admin && values.team_id === '') {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    path: ['team_id'],
                    message: t`Bitte ein Team auswählen.`,
                });
            }
        });

export function roleFormDefaults(user: AdminUser): RoleFormValues {
    const slugs = user.roles.map((assignment) => assignment.role.slug);
    const teamAdminAssignment = user.roles.find((assignment) => assignment.role.slug === 'team_admin');

    return {
        mandant_admin: slugs.includes('mandant_admin'),
        team_admin: slugs.includes('team_admin'),
        user: slugs.includes('user'),
        verifier: slugs.includes('verifier'),
        team_id:
            teamAdminAssignment?.team_id === null || teamAdminAssignment?.team_id === undefined
                ? ''
                : String(teamAdminAssignment.team_id),
    };
}

export function buildRolePayload(values: RoleFormValues): UserRoleInput[] {
    const payload: UserRoleInput[] = [];

    if (values.mandant_admin) {
        payload.push({ role: 'mandant_admin', team_id: null });
    }
    if (values.team_admin) {
        payload.push({ role: 'team_admin', team_id: values.team_id === '' ? null : Number(values.team_id) });
    }
    if (values.user) {
        payload.push({ role: 'user', team_id: null });
    }
    if (values.verifier) {
        payload.push({ role: 'verifier', team_id: null });
    }

    return payload;
}
