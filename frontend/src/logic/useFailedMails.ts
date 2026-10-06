import useSWR from 'swr';
import { listFailedMails } from '../api/client';
import type { FailedMail, PageMeta, Paginated } from '../api/types';

/**
 * The SWR cache key of one dead-letter page.
 *
 * The page number is PART OF THE KEY, and that is the whole point of the
 * function: one shared literal for a fixed resource would hand every page the
 * same cache entry, so "next" would re-render page 1 and the counter would lie
 * about what is on screen. `FAILED_MAILS_KEY` stays exported as the PATH so the
 * prefix is a constant like `MANDANTS_KEY` in `useMandants`, and the key is
 * built from it.
 */
export const FAILED_MAILS_KEY = '/api/admin/failed-mails';

/**
 * The page size the UI asks for.
 *
 * Duplicated from the server's default on purpose rather than "just letting the
 * server decide": `meta.per_page` is what the response really used, and the page
 * renders THAT, so a server-side default change shows up as the counter moving
 * rather than as a silent mismatch between the control and the window.
 */
export const FAILED_MAILS_PER_PAGE = 50;

/** The window before anything has been answered. Never shown as a real count. */
export const UNKNOWN_PAGE_META: PageMeta = {
    page: 1,
    per_page: FAILED_MAILS_PER_PAGE,
    total: 0,
    last_page: 1,
};

export interface UseFailedMailsResult {
    /** `undefined` while the page is still loading. */
    failedMails: FailedMail[] | undefined;
    /** The window the SERVER reported for the loaded page, `undefined` while loading. */
    meta: PageMeta | undefined;
    isLoading: boolean;
    error: unknown;
    /** Revalidates after a requeue, so the row that left `failed_jobs` leaves the page too. */
    revalidate: () => Promise<unknown>;
}

/**
 * One page of the mandant DLQ (`GET /api/admin/failed-mails?page=&per_page=`).
 *
 * No `enabled` switch, unlike `useMandants`: the route and the sidebar entry
 * are gated to exactly the roles that hold `mails.dlq.manage`
 * (`super_admin`, `mandant_admin`), so every mount is a request the API answers.
 *
 * `page` belongs to the CACHE KEY, not to a refetch effect: the previous page's
 * rows must not stay on screen while the next one loads, or "next" would appear
 * to do nothing until it finished. Returning `undefined` while the new key is
 * pending is what makes the page render its own loading state instead.
 */
export function useFailedMails(page: number): UseFailedMailsResult {
    const { data, error, isLoading, mutate } = useSWR<Paginated<FailedMail>>(
        `${FAILED_MAILS_KEY}?page=${page}&per_page=${FAILED_MAILS_PER_PAGE}`,
        () => listFailedMails({ page, perPage: FAILED_MAILS_PER_PAGE }),
    );

    return { failedMails: data?.data, meta: data?.meta, isLoading, error, revalidate: () => mutate() };
}
