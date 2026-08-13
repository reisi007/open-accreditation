import useSWR from 'swr';
import { listMandants, listTeams } from '../api/client';
import type { Mandant, Team } from '../api/types';
import { useAuth } from './useAuth';

/**
 * The teams a super_admin / mandant_admin may assign categories and events
 * to. The current mandant is resolved from the domain on the backend; the
 * frontend approximates it as:
 *   - super_admin  → the primary mandant (the domain-derived current mandant
 *     in local/dev, where the loopback host falls back to the primary)
 *   - mandant_admin → `mandant_id` of his role assignment
 * team_admin gets no team data — his team is fixed server-side.
 */
export function useAdminTeams(): { teams: Team[] | undefined; isLoading: boolean; error: unknown } {
    const { user } = useAuth();
    const roles = user?.roles ?? [];
    const isSuperAdmin = roles.some((role) => role.slug === 'super_admin');
    const mandantAdminRole = roles.find((role) => role.slug === 'mandant_admin');

    const { data: mandants } = useSWR<Mandant[]>(isSuperAdmin ? '/api/admin/mandants' : null, () => listMandants());
    const primaryMandantId = (mandants ?? []).find((mandant) => mandant.is_primary)?.id ?? null;

    const mandantId = isSuperAdmin ? primaryMandantId : (mandantAdminRole?.mandant_id ?? null);
    const teamKey = mandantId === null ? null : `/api/admin/mandants/${mandantId}/teams`;
    const teamFetcher = mandantId === null ? null : () => listTeams(mandantId);

    const { data: teams, isLoading, error } = useSWR<Team[]>(teamKey, teamFetcher);

    return { teams, isLoading, error };
}
