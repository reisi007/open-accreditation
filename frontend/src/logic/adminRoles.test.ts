import { describe, expect, it } from 'vitest';
import type { User } from '../api/types';
import { ACCOUNT_DELETER_ROLE_SLUGS, canDeleteUserAccounts, isAdminUser } from './adminRoles';

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
     * `verifier` hold neither — the same pair that holds `users.manage`, which
     * is why the existing `RequireRoles` route gate already covers the page.
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
