import { describe, expect, it } from 'vitest';
import type { User } from '../api/types';
import {
    ACCOUNT_DELETER_ROLE_SLUGS,
    canAssignUserRoles,
    canDeleteUserAccounts,
    isAdminUser,
    isTeamAdminUser,
    ROLE_ASSIGNER_ROLE_SLUGS,
} from './adminRoles';

function userWith(...slugs: string[]): User {
    return {
        id: 1,
        name: 'Test',
        email: 'test@example.test',
        current_mandant_id: 1,
        roles: slugs.map((slug) => ({ slug, name: slug, mandant_id: 1, team_id: null })),
    };
}

describe('canDeleteUserAccounts', () => {
    /**
     * The set is read off `backend/config/permissions.php`: `users.delete` is
     * held by `mandant_admin`, and `super_admin` holds `'*'`, which
     * `Gate::before` grants mandant-independently. `team_admin`, `user` and
     * `verifier` hold neither.
     *
     * F4 (Nutzerentscheid 2026-10-06): this used to be the SAME set that holds
     * `users.manage`, which made the separation of role assignment and account
     * termination a declaration without a carrier. `team_admin` joined
     * `users.manage` and is still absent here — that difference is the whole
     * point, and the two lists are asserted apart below.
     */
    it('allows exactly the roles that hold users.delete', () => {
        expect(canDeleteUserAccounts(userWith('super_admin'))).toBe(true);
        expect(canDeleteUserAccounts(userWith('mandant_admin'))).toBe(true);
        expect(canDeleteUserAccounts(userWith('team_admin'))).toBe(false);
        expect(canDeleteUserAccounts(userWith('user'))).toBe(false);
        expect(canDeleteUserAccounts(userWith('verifier'))).toBe(false);
    });

    it('denies an unauthenticated or unloaded session', () => {
        expect(canDeleteUserAccounts(null)).toBe(false);
        expect(canDeleteUserAccounts(undefined)).toBe(false);
        expect(canDeleteUserAccounts(userWith())).toBe(false);
    });

    /**
     * A multi-role account is the case a naive `roles[0] === …` check gets
     * wrong: a `user` who also holds `mandant_admin` may delete accounts.
     */
    it('accepts a holder among several roles and ignores the order', () => {
        expect(canDeleteUserAccounts(userWith('user', 'team_admin', 'mandant_admin'))).toBe(true);
        expect(canDeleteUserAccounts(userWith('team_admin', 'user'))).toBe(false);
    });

    /**
     * The UI gate is a SUFFICIENT condition for the page, not a necessary one
     * for the person: `isAdminUser` is strictly wider (it includes
     * `team_admin`), so the two must not be conflated. If they ever collapse to
     * the same list, the page would start offering a destructive action to a
     * role the backend refuses.
     */
    it('is narrower than the admin role set it sits next to', () => {
        expect(isAdminUser(userWith('team_admin'))).toBe(true);
        expect(canDeleteUserAccounts(userWith('team_admin'))).toBe(false);
        expect(ACCOUNT_DELETER_ROLE_SLUGS).toEqual(['super_admin', 'mandant_admin']);
    });
});

describe('canAssignUserRoles', () => {
    /**
     * `users.manage` is held by `mandant_admin` (whole mandant) and, since F4,
     * by `team_admin` for his own team(s). `super_admin` holds `'*'`.
     */
    it('allows exactly the roles that hold users.manage', () => {
        expect(canAssignUserRoles(userWith('super_admin'))).toBe(true);
        expect(canAssignUserRoles(userWith('mandant_admin'))).toBe(true);
        expect(canAssignUserRoles(userWith('team_admin'))).toBe(true);
        expect(canAssignUserRoles(userWith('user'))).toBe(false);
        expect(canAssignUserRoles(userWith('verifier'))).toBe(false);
    });

    it('denies an unauthenticated or unloaded session', () => {
        expect(canAssignUserRoles(null)).toBe(false);
        expect(canAssignUserRoles(undefined)).toBe(false);
        expect(canAssignUserRoles(userWith())).toBe(false);
    });

    /**
     * THE F4 assertion, in the shape the product states it: a `team_admin` can
     * assign roles and cannot terminate accounts. If these two lists ever become
     * equal again, the separation is a declaration without a carrier — which is
     * exactly the defect F4 was commissioned to end, and it is measurable only
     * while a role sits in one list and not the other.
     */
    it('separates role assignment from account termination', () => {
        const assignerOnly = ROLE_ASSIGNER_ROLE_SLUGS.filter((slug) => !ACCOUNT_DELETER_ROLE_SLUGS.includes(slug));
        const deleterOnly = ACCOUNT_DELETER_ROLE_SLUGS.filter((slug) => !ROLE_ASSIGNER_ROLE_SLUGS.includes(slug));

        expect(assignerOnly).toEqual(['team_admin']);
        expect(deleterOnly).toEqual([]);

        expect(canAssignUserRoles(userWith('team_admin'))).toBe(true);
        expect(canDeleteUserAccounts(userWith('team_admin'))).toBe(false);
    });
});

describe('isTeamAdminUser', () => {
    it('reads the session roles, not the first one', () => {
        expect(isTeamAdminUser(userWith('team_admin'))).toBe(true);
        expect(isTeamAdminUser(userWith('user', 'team_admin'))).toBe(true);
        expect(isTeamAdminUser(userWith('mandant_admin'))).toBe(false);
        expect(isTeamAdminUser(userWith())).toBe(false);
        expect(isTeamAdminUser(null)).toBe(false);
    });
});
