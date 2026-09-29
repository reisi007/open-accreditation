import type { User } from '../api/types';

export const ADMIN_ROLE_SLUGS: readonly string[] = ['super_admin', 'mandant_admin', 'team_admin'];

export function isAdminUser(user: User | null | undefined): boolean {
    return (user?.roles ?? []).some((role) => ADMIN_ROLE_SLUGS.includes(role.slug));
}

export function isSuperAdminUser(user: User | null | undefined): boolean {
    return (user?.roles ?? []).some((role) => role.slug === 'super_admin');
}

export function isMandantAdminUser(user: User | null | undefined): boolean {
    return (user?.roles ?? []).some((role) => role.slug === 'mandant_admin');
}

/**
 * The roles that may TERMINATE an account (`users.delete`).
 *
 * ## Why a role list, and not a permission string
 *
 * `/api/auth/me` (`UserResource`) serialises identity and ROLES — it has no
 * permission list, and this frontend never received one. The existing
 * authorisation plumbing for the admin surface is therefore exactly this:
 * role slugs out of the session, checked by `RequireRoles` on the route
 * (`App.tsx`, `super_admin` + `mandant_admin` around `/admin/users`). This
 * predicate is the same check, named — so the destructive action on the user
 * list is gated by the source of truth that is already in use, and not by an
 * invented parallel one.
 *
 * The set is read off `backend/config/permissions.php`: `users.delete` is held
 * by `mandant_admin`; `super_admin` holds `'*'`, which `Gate::before`
 * (`AuthServiceProvider:28`) grants mandant-independently. `team_admin`,
 * `user` and `verifier` hold neither — the same set that holds `users.manage`,
 * which is why the existing route gate already covers the page.
 *
 * The backend gate stays the authorisation. This is a UI affordance: a role
 * that gains `users.delete` in the matrix must be added HERE too, or the
 * button is missing for a role that may legitimately delete.
 */
export const ACCOUNT_DELETER_ROLE_SLUGS: readonly string[] = ['super_admin', 'mandant_admin'];

export function canDeleteUserAccounts(user: User | null | undefined): boolean {
    return (user?.roles ?? []).some((role) => ACCOUNT_DELETER_ROLE_SLUGS.includes(role.slug));
}
