import type { AccountDeletionResult } from '../api/types';

/**
 * The one-shot message shown after a SELF-service account deletion.
 *
 * ## Why this exists at all
 *
 * A self-deletion cannot leave the user on the page that reported it. The
 * account row is gone, so the very next `/api/auth/me` answers 401, the
 * `RequireAuth` guard redirects, and any page behind it becomes a page the
 * user must not see. The session therefore has to be torn down and the app
 * navigated to the public start page — which means the ONLY place the result
 * can still be shown is a message that survives the navigation.
 *
 * ## Why the router state, and why it is validated
 *
 * `navigate('/', { state })` is the navigation the app already performs for
 * logout (`AuthNav`), so this is not a new mechanism but a payload on it.
 * `location.state` is untyped and survives a reload, which makes it exactly
 * the sort of value a reader must not trust: `readAccountDeletedNotice`
 * therefore takes `unknown` and checks every field, so a stale/foreign state
 * (a bookmark, a hand-crafted history entry) renders nothing instead of
 * rendering `"3 Anträge gelöscht"` out of thin air.
 */
export interface AccountDeletedNotice {
    result: AccountDeletionResult;
}

function isCount(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0;
}

function isStringArray(value: unknown): value is string[] {
    return Array.isArray(value) && value.every((entry) => typeof entry === 'string');
}

function isDeletionResult(value: unknown): value is AccountDeletionResult {
    if (value === null || typeof value !== 'object') {
        return false;
    }

    const candidate = value as Record<string, unknown>;

    return (
        isCount(candidate.applications_deleted) &&
        isCount(candidate.sub_applications_deleted) &&
        isCount(candidate.media_files_deleted) &&
        isCount(candidate.role_assignments_deleted) &&
        isCount(candidate.sessions_deleted) &&
        isStringArray(candidate.media_files_left_over)
    );
}

/** The router-state key. Named, so the writer and the reader cannot drift. */
export const ACCOUNT_DELETED_STATE_KEY = 'accountDeleted';

/**
 * Reads the notice out of an untyped `location.state`, or `null`.
 *
 * `null` is the normal answer: it is what every ordinary navigation to the
 * start page produces, and the caller then renders no message at all.
 */
export function readAccountDeletedNotice(state: unknown): AccountDeletedNotice | null {
    if (state === null || typeof state !== 'object') {
        return null;
    }

    const candidate = (state as Record<string, unknown>)[ACCOUNT_DELETED_STATE_KEY];

    if (!isDeletionResult(candidate)) {
        return null;
    }

    return { result: candidate };
}

/** The state to hand to the navigation that leaves the deleted account. */
export function accountDeletedState(result: AccountDeletionResult): { [ACCOUNT_DELETED_STATE_KEY]: AccountDeletionResult } {
    return { [ACCOUNT_DELETED_STATE_KEY]: result };
}
