import useSWR from 'swr';
import { listFailedMails } from '../api/client';
import type { FailedMail } from '../api/types';

/**
 * Shared SWR cache key of the dead-letter list.
 *
 * Exported as a literal so the page, its tests and any second consumer cannot
 * drift into two cache entries for one resource — the same argument
 * `MANDANTS_KEY` carries in `useMandants`.
 */
export const FAILED_MAILS_KEY = '/api/admin/failed-mails';

export interface UseFailedMailsResult {
    /** `undefined` while the list is still loading. */
    failedMails: FailedMail[] | undefined;
    isLoading: boolean;
    error: unknown;
    /** Revalidates after a requeue, so the row that left `failed_jobs` leaves the list too. */
    revalidate: () => Promise<unknown>;
}

/**
 * The dead letters of the mandant DLQ (`GET /api/admin/failed-mails`).
 *
 * No `enabled` switch, unlike `useMandants`: the route and the sidebar entry
 * are gated to exactly the roles that hold `mails.dlq.manage`
 * (`super_admin`, `mandant_admin`), so every mount is a request the API answers.
 */
export function useFailedMails(): UseFailedMailsResult {
    const { data, error, isLoading, mutate } = useSWR<FailedMail[]>(FAILED_MAILS_KEY, () => listFailedMails());

    return { failedMails: data, isLoading, error, revalidate: () => mutate() };
}
