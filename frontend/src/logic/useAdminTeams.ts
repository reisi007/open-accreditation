import useSWR from 'swr';
import { listTeams } from '../api/client';
import type { Team } from '../api/types';
import { useAuth } from './useAuth';

export interface UseAdminTeamsResult {
    teams: Team[] | undefined;
    isLoading: boolean;
    error: unknown;
    /**
     * For a team_admin the ids of his own team(s) (from the `/me` role
     * assignments). Empty for super_admin / mandant_admin, who are not
     * restricted to a team scope.
     */
    currentTeamIds: number[];
}

/**
 * The teams a super_admin / mandant_admin / team_admin may assign categories
 * and events to. The current mandant is resolved from the domain on the
 * backend; the frontend uses it as:
 *   - super_admin  → `current_mandant_id` from `/me` (the host-derived mandant
 *     from MandantContext — on a non-primary domain this is that mandant, not
 *     the primary one)
 *   - mandant_admin → `mandant_id` of his role assignment (all teams)
 *   - team_admin    → `mandant_id` of his team_admin assignment; the backend
 *     (`teams.view`) returns only his own teams
 */
export function useAdminTeams(): UseAdminTeamsResult {
    const { user } = useAuth();
    const roles = user?.roles ?? [];
    const isSuperAdmin = roles.some((role) => role.slug === 'super_admin');
    const isTeamAdmin = roles.some((role) => role.slug === 'team_admin');
    const mandantAdminRole = roles.find((role) => role.slug === 'mandant_admin');
    const teamAdminRole = roles.find((role) => role.slug === 'team_admin');

    // super_admin: use the current mandant resolved from the request host
    // (backend MandantContext via `/me`). On a non-primary domain this is the
    // host's mandant — not the primary mandant's teams.
    const mandantId = isSuperAdmin
        ? (user?.current_mandant_id ?? null)
        : (mandantAdminRole?.mandant_id ?? teamAdminRole?.mandant_id ?? null);
    const teamKey = mandantId === null ? null : `/api/admin/mandants/${mandantId}/teams`;
    const teamFetcher = mandantId === null ? null : () => listTeams(mandantId);

    const { data: teams, isLoading, error } = useSWR<Team[]>(teamKey, teamFetcher);

    const currentTeamIds = isTeamAdmin
        ? roles
              .filter((role) => role.slug === 'team_admin' && role.team_id !== null)
              .map((role) => role.team_id as number)
        : [];

    return { teams, isLoading, error, currentTeamIds };
}
