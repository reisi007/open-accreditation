import useSWR from 'swr';
import { listMandants, listTeams } from '../api/client';
import type { Mandant, Team } from '../api/types';
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
 * backend; the frontend approximates it as:
 *   - super_admin  → the primary mandant (the domain-derived current mandant
 *     in local/dev, where the loopback host falls back to the primary)
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

    const { data: mandants } = useSWR<Mandant[]>(isSuperAdmin ? '/api/admin/mandants' : null, () => listMandants());
    const primaryMandantId = (mandants ?? []).find((mandant) => mandant.is_primary)?.id ?? null;

    const mandantId = isSuperAdmin
        ? primaryMandantId
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
