import { describe, expect, it } from 'vitest';
import type { AccountDeletionResult } from '../api/types';
import { ACCOUNT_DELETED_STATE_KEY, accountDeletedState, readAccountDeletedNotice } from './accountDeletedNotice';

const result: AccountDeletionResult = {
    applications_deleted: 3,
    sub_applications_deleted: 1,
    media_files_deleted: 2,
    role_assignments_deleted: 1,
    sessions_deleted: 0,
    media_files_left_over: [],
};

describe('readAccountDeletedNotice', () => {
    it('reads the notice the account page handed to the navigation', () => {
        expect(readAccountDeletedNotice(accountDeletedState(result))).toEqual({ result });
    });

    it('returns null for the state of every ordinary navigation', () => {
        expect(readAccountDeletedNotice(null)).toBeNull();
        expect(readAccountDeletedNotice(undefined)).toBeNull();
        expect(readAccountDeletedNotice({})).toBeNull();
        expect(readAccountDeletedNotice('konto')).toBeNull();
    });

    /**
     * `location.state` is untyped and survives a reload, so a stale or
     * hand-crafted history entry must render NOTHING rather than a plausible
     * looking "3 Anträge gelöscht". Every field is checked, including the
     * `string[]` — a reader that trusted it would put an object into
     * `join(', ')` and crash the start page of a logged-out user.
     */
    it('rejects a payload that is not a complete deletion result', () => {
        const incomplete = [
            { ...result, applications_deleted: undefined },
            { ...result, media_files_left_over: 'media/1/a.png' },
            { ...result, media_files_left_over: [1, 2] },
            { ...result, sessions_deleted: -1 },
            { ...result, sessions_deleted: 1.5 },
            { ...result, media_files_left_over: null },
        ];

        for (const payload of incomplete) {
            expect(readAccountDeletedNotice({ [ACCOUNT_DELETED_STATE_KEY]: payload })).toBeNull();
        }
    });

    it('does not read a notice from an unrelated key', () => {
        expect(readAccountDeletedNotice({ somethingElse: result })).toBeNull();
    });
});
