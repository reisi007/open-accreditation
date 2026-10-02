import { describe, expect, it } from 'vitest';
import { i18n } from '@lingui/core';
import { ApiError } from '../../api/client';
// Side effect: loads and activates the German catalog on the shared i18n
// instance, so the assertions below compare RENDERED text and not message ids.
import '../../logic/I18nProvider';
import type { FailedMail } from '../../api/types';
import {
    FAILED_MAIL_EXCEPTION_LIMIT,
    failedMailListErrorMessage,
    filterFailedMails,
    isTruncatedException,
    requeueFailedMailErrorMessage,
} from './failedMailUtils';

/**
 * A real `Str::limit(..., 500)` result: 497 characters plus the end marker.
 * Laravel's default `$end` is `'...'`.
 */
function cutException(): string {
    return `${'E'.repeat(FAILED_MAIL_EXCEPTION_LIMIT - 3)}...`;
}

function row(overrides: Partial<FailedMail> = {}): FailedMail {
    return {
        id: 1,
        mandant_id: 1,
        mailable: 'App\\Mail\\PassMail',
        recipient: 'anna@example.test',
        queue: 'default',
        exception: 'Connection could not be established with host smtp.example.test:587',
        failed_at: '2026-10-02T09:30:00+00:00',
        ...overrides,
    };
}

describe('isTruncatedException', () => {
    it('recognises a server-cut exception by its end marker', () => {
        expect(isTruncatedException(cutException())).toBe(true);
    });

    it('leaves a whole exception unlabelled', () => {
        expect(isTruncatedException(row().exception)).toBe(false);
        expect(isTruncatedException('')).toBe(false);
    });

    it('detects the cut even when the trace is multibyte', () => {
        // Why this case exists: the obvious tightening would be "marker AND a
        // length near 500", which is WRONG because `Str::limit` counts DISPLAY
        // WIDTH (`mb_strimwidth`). 250 full-width characters are 500 wide but
        // only 250 `.length` units, so a length test would miss this cut and the
        // UI would show half a trace as if it were whole — the exact failure the
        // marker exists to prevent.
        const wide = `${'画'.repeat(250)}...`;
        expect(wide.length).toBeLessThan(FAILED_MAIL_EXCEPTION_LIMIT - 3);
        expect(isTruncatedException(wide)).toBe(true);
    });
});

describe('filterFailedMails', () => {
    const rows = [
        row({ id: 1, mandant_id: 1, recipient: 'anna@example.test', mailable: 'App\\Mail\\PassMail' }),
        row({ id: 2, mandant_id: 2, recipient: 'bernd@example.test', mailable: 'App\\Mail\\ActivationMail' }),
        row({ id: 3, mandant_id: 1, recipient: 'carla@example.test', queue: 'mail-high' }),
    ];

    it('returns everything for an empty filter', () => {
        expect(filterFailedMails(rows, { search: '', mandantId: null })).toHaveLength(3);
    });

    it('narrows to one mandant', () => {
        expect(filterFailedMails(rows, { search: '', mandantId: 2 }).map((entry) => entry.id)).toEqual([2]);
    });

    it('searches recipient, mailable, queue and exception case-insensitively', () => {
        expect(filterFailedMails(rows, { search: 'BERND@', mandantId: null }).map((e) => e.id)).toEqual([2]);
        expect(filterFailedMails(rows, { search: 'activation', mandantId: null }).map((e) => e.id)).toEqual([2]);
        expect(filterFailedMails(rows, { search: 'mail-high', mandantId: null }).map((e) => e.id)).toEqual([3]);
        expect(filterFailedMails(rows, { search: 'could not be established', mandantId: null })).toHaveLength(3);
    });

    it('combines search and mandant scope', () => {
        expect(filterFailedMails(rows, { search: 'carla', mandantId: 2 })).toHaveLength(0);
    });

    it('treats a whitespace-only search as no filter', () => {
        expect(filterFailedMails(rows, { search: '   ', mandantId: null })).toHaveLength(3);
    });

    it('survives the nullable fields instead of matching on "null"', () => {
        const sparse = [row({ id: 9, recipient: null, mailable: null })];

        expect(filterFailedMails(sparse, { search: 'null', mandantId: null })).toHaveLength(0);
        expect(filterFailedMails(sparse, { search: '', mandantId: null })).toHaveLength(1);
    });
});

describe('requeueFailedMailErrorMessage', () => {
    const t = i18n;

    it('names BOTH meanings of the 404 a requeue can answer', () => {
        // A foreign letter is 404, not 403 (`FailedMailController::requeue`), and
        // so is a letter that is gone. A message that said only "not found"
        // would let a mandant_admin read "the queue is empty" — the failure this
        // stream exists to prevent.
        const message = requeueFailedMailErrorMessage(new ApiError(404, 'No query results.', {}), t);

        expect(message).toContain('existiert nicht (mehr)');
        expect(message).toContain('anderen Verband');
        expect(message).toContain('404');
    });

    it('does not swallow the 403 of a role without the gate', () => {
        expect(requeueFailedMailErrorMessage(new ApiError(403, 'This action is unauthorized.', {}), t)).toContain(
            'Keine Berechtigung',
        );
    });

    it('names the throttle, which the requeue route really carries', () => {
        expect(requeueFailedMailErrorMessage(new ApiError(429, 'Too Many Attempts.', {}), t)).toContain(
            'Zu viele Versuche',
        );
    });

    it('falls back to the ApiError message for other statuses', () => {
        expect(requeueFailedMailErrorMessage(new ApiError(500, 'Server Error', {}), t)).toBe('Server Error');
    });

    it('falls back to its own text for a non-ApiError', () => {
        expect(requeueFailedMailErrorMessage(new Error('boom'), t)).toBe(
            'Der Brief konnte nicht erneut eingereiht werden.',
        );
    });
});

describe('failedMailListErrorMessage', () => {
    const t = i18n;

    it('distinguishes the list 403 from the list 404', () => {
        expect(failedMailListErrorMessage(new ApiError(403, '', {}), t)).toContain('Keine Berechtigung');
        expect(failedMailListErrorMessage(new ApiError(404, '', {}), t)).toContain('nicht erreichbar');
    });

    it('never reports a load failure as an empty queue', () => {
        // The load error and the empty state are different outcomes and must
        // never read alike: one is "nothing went wrong, nothing dead", the other
        // is "we do not know what is dead".
        expect(failedMailListErrorMessage(new ApiError(500, 'Server Error', {}), t)).toBe('Server Error');
        expect(failedMailListErrorMessage(new Error('offline'), t)).toBe('Tote Briefe konnten nicht geladen werden.');
    });
});
