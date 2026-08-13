import { describe, expect, it } from 'vitest';
import type { AdminUser } from '../../api/types';
import { buildRolePayload, createRoleSchema, roleFormDefaults, type RoleFormValues } from './userRoleFormUtils';

const baseValues: RoleFormValues = {
    mandant_admin: false,
    team_admin: false,
    user: false,
    verifier: false,
    team_id: '',
};

function valuesWith(overrides: Partial<RoleFormValues>): RoleFormValues {
    return { ...baseValues, ...overrides };
}

function userWith(roles: Array<{ slug: string; team_id?: number | null }>): AdminUser {
    return {
        id: 1,
        name: 'Test User',
        email: 'test@example.test',
        roles: roles.map((entry) => ({
            role: { slug: entry.slug, name: entry.slug },
            mandant_id: 1,
            team_id: entry.team_id ?? null,
            team: entry.team_id ? { id: entry.team_id, name: 'Musterverein' } : null,
        })),
    };
}

describe('buildRolePayload', () => {
    it('maps checked roles onto the API payload', () => {
        const payload = buildRolePayload(
            valuesWith({ mandant_admin: true, team_admin: true, user: true, verifier: true, team_id: '7' }),
        );

        expect(payload).toEqual([
            { role: 'mandant_admin', team_id: null },
            { role: 'team_admin', team_id: 7 },
            { role: 'user', team_id: null },
            { role: 'verifier', team_id: null },
        ]);
    });

    it('sends a single user role with null team_id', () => {
        expect(buildRolePayload(valuesWith({ user: true }))).toEqual([{ role: 'user', team_id: null }]);
    });

    it('maps the team select string to a number for team_admin', () => {
        const payload = buildRolePayload(valuesWith({ team_admin: true, team_id: '42' }));

        expect(payload).toEqual([{ role: 'team_admin', team_id: 42 }]);
    });

    it('emits null team_id for team_admin when no team is selected', () => {
        const payload = buildRolePayload(valuesWith({ team_admin: true, team_id: '' }));

        expect(payload).toEqual([{ role: 'team_admin', team_id: null }]);
    });
});

describe('roleFormDefaults', () => {
    it('maps the current role assignments onto the form', () => {
        const defaults = roleFormDefaults(
            userWith([
                { slug: 'team_admin', team_id: 5 },
                { slug: 'user' },
            ]),
        );

        expect(defaults).toEqual({
            mandant_admin: false,
            team_admin: true,
            user: true,
            verifier: false,
            team_id: '5',
        });
    });

    it('uses an empty team_id when the user has no team_admin assignment', () => {
        const defaults = roleFormDefaults(userWith([{ slug: 'user' }]));

        expect(defaults).toEqual({ ...baseValues, user: true });
    });
});

describe('createRoleSchema', () => {
    it('accepts a single selected role', () => {
        const schema = createRoleSchema();

        expect(schema.safeParse(valuesWith({ user: true })).success).toBe(true);
    });

    it('accepts team_admin with a team selected', () => {
        const schema = createRoleSchema();

        expect(schema.safeParse(valuesWith({ team_admin: true, team_id: '9' })).success).toBe(true);
    });

    it('rejects an empty role set', () => {
        const schema = createRoleSchema();

        expect(schema.safeParse(baseValues).success).toBe(false);
    });

    it('rejects team_admin without a team selected', () => {
        const schema = createRoleSchema();

        const result = schema.safeParse(valuesWith({ team_admin: true, team_id: '' }));

        expect(result.success).toBe(false);
        if (!result.success) {
            const issue = result.error.issues.find((entry) => entry.path[0] === 'team_id');
            expect(issue).toBeDefined();
        }
    });
});
