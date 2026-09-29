import { i18n } from '@lingui/core';
import { describe, expect, it } from 'vitest';
import type { AccountDeletionResult } from '../api/types';
import {
    deletedApplicationsLabel,
    deletionSuccessMessage,
    mediaResidueWarning,
    pendingApplicationsLabel,
    pendingMediaLabel,
    pendingSubApplicationsLabel,
} from './accountDeletion';
// Side effect: loads and activates the German catalog on the shared i18n instance.
import './I18nProvider';

const result = (overrides: Partial<AccountDeletionResult> = {}): AccountDeletionResult => ({
    applications_deleted: 0,
    sub_applications_deleted: 0,
    media_files_deleted: 0,
    role_assignments_deleted: 0,
    sessions_deleted: 0,
    media_files_left_over: [],
    ...overrides,
});

/**
 * The count helpers MUST interpolate the count.
 *
 * Same blind spot as `accreditationLabels.ts`: the `t` macro injects `values`
 * only for `${…}` placeholders, never for a named ICU argument, so
 * `i18n._(t\`{count, plural, …}\`)` renders "NaN Anträge". Nothing in the tool
 * chain sees it — hence exact expectations on the rendered NUMBER here, and
 * the same reason the confirmation dialog renders through these helpers rather
 * than through a second copy of the ICU string.
 */
describe('pending count labels', () => {
    it('interpolates the singular and plural application forms', () => {
        expect(pendingApplicationsLabel(0, i18n)).toBe('0 Anträge');
        expect(pendingApplicationsLabel(1, i18n)).toBe('1 Antrag');
        expect(pendingApplicationsLabel(3, i18n)).toBe('3 Anträge');
    });

    it('interpolates the singular and plural sub-application forms', () => {
        expect(pendingSubApplicationsLabel(1, i18n)).toBe('1 Sub-Antrag');
        expect(pendingSubApplicationsLabel(2, i18n)).toBe('2 Sub-Anträge');
    });

    it('interpolates the file counts on both surfaces', () => {
        expect(pendingMediaLabel(0, i18n)).toBe('0 Dateien');
        expect(pendingMediaLabel(2, i18n)).toBe('2 Dateien');
        expect(deletedApplicationsLabel(result({ applications_deleted: 5 }), i18n)).toBe('5 Anträge');
    });
});

/**
 * `media_files_left_over` is a WARNING, never an error — a file that cannot be
 * unlinked must not turn a completed deletion into a failed request. The
 * `null` return is what carries that: the callers render the warning block
 * only for a non-null value, so a clean deletion cannot be reported as an
 * alarming notice about a path list that does not exist.
 */
describe('mediaResidueWarning', () => {
    it('returns null for a clean deletion', () => {
        expect(mediaResidueWarning([], i18n)).toBeNull();
    });

    it('names every surviving storage path', () => {
        const warning = mediaResidueWarning(['media/1/a.png', 'media/1/b.pdf'], i18n);
        expect(warning).not.toBeNull();
        expect(warning).toContain('media/1/a.png');
        expect(warning).toContain('media/1/b.pdf');
        expect(warning).toContain('Achtung');
    });
});

describe('deletionSuccessMessage', () => {
    it('reports the counts the backend measured, in the wording of the own-account route', () => {
        const message = deletionSuccessMessage(
            result({ applications_deleted: 3, sub_applications_deleted: 1, media_files_deleted: 2 }),
            'self',
            i18n,
        );
        expect(message).toContain('Dein Konto wurde gelöscht.');
        expect(message).toContain('3 Anträge');
        expect(message).toContain('1 Sub-Antrag');
        expect(message).toContain('2 Dateien');
    });

    it('uses the admin wording for a deletion an admin performed', () => {
        const message = deletionSuccessMessage(result({ applications_deleted: 1 }), 'admin', i18n);
        expect(message).toContain('Das Konto wurde gelöscht.');
        expect(message).not.toContain('Dein Konto');
    });

    it('never renders the unbound-argument placeholder', () => {
        for (const count of [0, 1, 7, 42]) {
            const message = deletionSuccessMessage(
                result({ applications_deleted: count, sub_applications_deleted: count, media_files_deleted: count }),
                'self',
                i18n,
            );
            expect(message).not.toContain('NaN');
        }
    });
});
