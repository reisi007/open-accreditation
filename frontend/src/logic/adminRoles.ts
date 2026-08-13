import type { User } from '../api/types';

export const ADMIN_ROLE_SLUGS: readonly string[] = ['super_admin', 'mandant_admin', 'team_admin'];

export function isAdminUser(user: User | null | undefined): boolean {
    return (user?.roles ?? []).some((role) => ADMIN_ROLE_SLUGS.includes(role.slug));
}

export function isSuperAdminUser(user: User | null | undefined): boolean {
    return (user?.roles ?? []).some((role) => role.slug === 'super_admin');
}
