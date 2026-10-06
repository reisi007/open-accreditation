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

export function isTeamAdminUser(user: User | null | undefined): boolean {
    return (user?.roles ?? []).some((role) => role.slug === 'team_admin');
}

/**
 * The roles that may ASSIGN roles (`users.manage`) — the read side of the same
 * permission, and the counterpart of `ACCOUNT_DELETER_ROLE_SLUGS`.
 *
 * Read off `backend/config/permissions.php`: `mandant_admin` over the whole
 * mandant, and — since F4 (Nutzerentscheid 2026-10-06) — `team_admin` for his
 * OWN team(s). `super_admin` holds `'*'`, which `Gate::before`
 * (`AuthServiceProvider:28`) grants mandant-independently.
 *
 * Why `team_admin` is here at all: before F4 this set was the SAME as
 * `ACCOUNT_DELETER_ROLE_SLUGS`, which made the separation of role assignment
 * and account termination a declaration without a carrier — swapping the
 * delete route's gate was measured invisible (30/30 and 64/64 green). A
 * `team_admin` may open the page and edit roles; he may not terminate anyone,
 * and `UsersPage` renders no delete action for him.
 *
 * The backend gate stays the authorisation. This is a UI affordance: a role
 * that gains `users.manage` in the matrix must be added HERE too, or the page
 * becomes unreachable for a role that may legitimately assign.
 */
export const ROLE_ASSIGNER_ROLE_SLUGS: readonly string[] = ['super_admin', 'mandant_admin', 'team_admin'];

export function canAssignUserRoles(user: User | null | undefined): boolean {
    return (user?.roles ?? []).some((role) => ROLE_ASSIGNER_ROLE_SLUGS.includes(role.slug));
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
 * (`App.tsx`). This predicate is the same check, named — so the destructive
 * action on the user list is gated by the source of truth that is already in
 * use, and not by an invented parallel one.
 *
 * The set is read off `backend/config/permissions.php`: `users.delete` is held
 * by `mandant_admin`; `super_admin` holds `'*'`, which `Gate::before` grants
 * mandant-independently. `team_admin`, `user` and `verifier` hold it NOT —
 * which is precisely what separates this set from `ROLE_ASSIGNER_ROLE_SLUGS`
 * above, where `team_admin` IS present.
 *
 * The backend gate stays the authorisation. This is a UI affordance: a role
 * that gains `users.delete` in the matrix must be added HERE too, or the
 * button is missing for a role that may legitimately delete.
 */
export const ACCOUNT_DELETER_ROLE_SLUGS: readonly string[] = ['super_admin', 'mandant_admin'];

export function canDeleteUserAccounts(user: User | null | undefined): boolean {
    return (user?.roles ?? []).some((role) => ACCOUNT_DELETER_ROLE_SLUGS.includes(role.slug));
}
