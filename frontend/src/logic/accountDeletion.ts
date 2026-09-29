import type { I18n } from '@lingui/core';
import { msg, t } from '@lingui/core/macro';
import type { AccountDeletionResult } from '../api/types';

/**
 * Copy and derived state for account termination (DSGVO), shared by the two
 * surfaces that can trigger it:
 *
 *   - the own-account area (`pages/AccountPage.tsx`, `DELETE /api/user/account`)
 *   - the admin user list (`pages/admin/UsersPage.tsx`, `DELETE /api/admin/users/{id}`)
 *
 * Both MUST name the account and the number of applications in the
 * confirmation, and both MUST treat a non-empty `media_files_left_over` as a
 * WARNING rather than a failure — the account is gone either way, and the
 * residue is an operator fact, not a failed request. That makes the two rules
 * worth exactly one implementation: a copy function here, unit-tested against
 * the real German catalog, instead of two dialogs that drift.
 */

/** The plural rules share the `values` requirement — see `accreditationLabels.ts`. */
function applicationCountLabel(count: number, i18n: I18n): string {
    return i18n._({ ...msg`{count, plural, one {# Antrag} other {# Anträge}}`, values: { count } });
}

function subApplicationCountLabel(count: number, i18n: I18n): string {
    return i18n._({ ...msg`{count, plural, one {# Sub-Antrag} other {# Sub-Anträge}}`, values: { count } });
}

/** How much is about to go. The mandatory "number of applications" of the decision. */
export function pendingApplicationsLabel(applicationsCount: number, i18n: I18n): string {
    return applicationCountLabel(applicationsCount, i18n);
}

export function pendingSubApplicationsLabel(subApplicationsCount: number, i18n: I18n): string {
    return subApplicationCountLabel(subApplicationsCount, i18n);
}

export function pendingMediaLabel(mediaCount: number, i18n: I18n): string {
    return i18n._({ ...msg`{count, plural, one {# Datei} other {# Dateien}}`, values: { count: mediaCount } });
}

/** What was actually removed, measured inside the deletion transaction. */
export function deletedApplicationsLabel(result: AccountDeletionResult, i18n: I18n): string {
    return applicationCountLabel(result.applications_deleted, i18n);
}

export function deletedSubApplicationsLabel(result: AccountDeletionResult, i18n: I18n): string {
    return subApplicationCountLabel(result.sub_applications_deleted, i18n);
}

export function deletedMediaLabel(result: AccountDeletionResult, i18n: I18n): string {
    return i18n._({ ...msg`{count, plural, one {# Datei} other {# Dateien}}`, values: { count: result.media_files_deleted } });
}

/**
 * The WARNING about files that survived, or `null` when there are none.
 *
 * `null` is the load-bearing part: the callers render the warning block only
 * for a non-null return, so "the deletion went cleanly" cannot be rendered as
 * an alarming notice about a path list that does not exist. The paths are
 * storage locations, so they are shown verbatim — the operator has to be able
 * to find the file, and the backend deliberately kept them in the response.
 */
export function mediaResidueWarning(paths: readonly string[], i18n: I18n): string | null {
    if (paths.length === 0) {
        return null;
    }

    return `${i18n._(
        t`Achtung: Diese Datei konnte nicht gelöscht werden. Bitte den Support kontaktieren:`,
    )} ${paths.join(', ')}`;
}

/**
 * The success line after a deletion, in the words of the route that performed
 * it. The two backend messages differ on purpose ("Dein Konto …" vs "Konto
 * gelöscht.") and the UI keeps them apart rather than inventing a third voice.
 */
export function deletionSuccessMessage(
    result: AccountDeletionResult,
    scope: 'self' | 'admin',
    i18n: I18n,
): string {
    const headline =
        scope === 'self'
            ? i18n._(t`Dein Konto wurde gelöscht.`)
            : i18n._(t`Das Konto wurde gelöscht.`);
    const counts = `${deletedApplicationsLabel(result, i18n)}, ${deletedSubApplicationsLabel(result, i18n)}, ${deletedMediaLabel(result, i18n)}`;

    return `${headline} ${i18n._({ ...msg`Gelöscht: {counts}`, values: { counts } })}`;
}
