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

/**
 * The role slugs the role editor knows, in the order it renders them.
 *
 * F4 (Nutzerentscheid 2026-10-06): a `team_admin` reaches this editor and may
 * write ONLY `team_admin` for his own team(s) — the backend answers 403 for
 * every other entry, and `role_form` is that 403's mirror. The list is therefore
 * a parameter and not a constant, and the DEFAULT is the unrestricted set: a
 * `mandant_admin` sees all four. `super_admin` is absent on purpose and cannot
 * be added — it is global and comes from the seeder.
 */
export const ALL_ROLE_SLUGS = ['mandant_admin', 'team_admin', 'user', 'verifier'] as const;

export type AssignableRoleSlug = (typeof ALL_ROLE_SLUGS)[number];

/** What a narrowed (`team_admin`) session may assign — see `RoleForm`. */
export const TEAM_SCOPED_ROLE_SLUGS: readonly AssignableRoleSlug[] = ['team_admin'];

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

/**
 * The form state for one user, restricted to the roles this session may
 * assign.
 *
 * Roles outside `assignableRoles` are forced to `false` rather than merely
 * hidden: they are still registered inputs, so their value would otherwise be
 * carried over from the target's current roles and shipped in the payload —
 * which the backend answers 403 to. That would turn a harmless editor into a
 * permanently failing save.
 */
export function roleFormDefaults(user: AdminUser, assignableRoles: readonly AssignableRoleSlug[] = ALL_ROLE_SLUGS): RoleFormValues {
    const visible = user.roles.filter((assignment) => assignableRoles.includes(assignment.role.slug as AssignableRoleSlug));
    const slugs = visible.map((assignment) => assignment.role.slug);
    const teamAdminAssignment = visible.find((assignment) => assignment.role.slug === 'team_admin');

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

/**
 * The payload for `PUT /api/admin/users/{id}/roles`, restricted to the roles
 * this session may assign.
 *
 * The filter is not decoration: `UserController::updateRoles()` REPLACES the
 * target's role set, so a checkbox the backend would reject is not a harmless
 * extra entry — with an unfiltered payload the team_admin's save would be a
 * 403 on every submission. Roles he may not write are dropped from the payload
 * rather than sent and refused.
 */
export function buildRolePayload(
    values: RoleFormValues,
    assignableRoles: readonly AssignableRoleSlug[] = ALL_ROLE_SLUGS,
): UserRoleInput[] {
    const payload: UserRoleInput[] = [];

    if (values.mandant_admin && assignableRoles.includes('mandant_admin')) {
        payload.push({ role: 'mandant_admin', team_id: null });
    }
    if (values.team_admin && assignableRoles.includes('team_admin')) {
        payload.push({ role: 'team_admin', team_id: values.team_id === '' ? null : Number(values.team_id) });
    }
    if (values.user && assignableRoles.includes('user')) {
        payload.push({ role: 'user', team_id: null });
    }
    if (values.verifier && assignableRoles.includes('verifier')) {
        payload.push({ role: 'verifier', team_id: null });
    }

    return payload;
}
