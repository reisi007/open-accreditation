import { describe, expect, it } from 'vitest';
import {
    buildAllocationPayload,
    buildApplicationAction,
    buildBlacklistPayload,
    createBlacklistSchema,
} from './approvalFormUtils';

describe('buildApplicationAction', () => {
    it('builds an approve action', () => {
        expect(buildApplicationAction({ status: 'approved' })).toEqual({ status: 'approved' });
    });

    it('builds a deny action with reason', () => {
        expect(buildApplicationAction({ status: 'denied', reason: 'Zu viele Anträge.' })).toEqual({
            status: 'denied',
            reason: 'Zu viele Anträge.',
        });
    });

    it('builds a priority-only action', () => {
        expect(buildApplicationAction({ priority: true })).toEqual({ priority: true });
    });

    it('omits undefined fields', () => {
        expect(buildApplicationAction({ status: 'approved', reason: undefined, priority: undefined })).toEqual({
            status: 'approved',
        });
    });

    it('omits an empty reason', () => {
        expect(buildApplicationAction({ status: 'denied', reason: '   ' })).toEqual({ status: 'denied' });
    });
});

describe('createBlacklistSchema', () => {
    function parse(values: unknown) {
        return createBlacklistSchema().safeParse(values);
    }

    it('accepts an email', () => {
        expect(parse({ email: 'spam@example.com' }).success).toBe(true);
    });

    it('accepts a domain', () => {
        expect(parse({ domain: 'spam.example' }).success).toBe(true);
    });

    it('accepts email and domain together', () => {
        expect(parse({ email: 'spam@example.com', domain: 'example.com' }).success).toBe(true);
    });

    it('accepts a note alone next to an email', () => {
        expect(parse({ email: 'spam@example.com', note: 'Bekannt' }).success).toBe(true);
    });

    it('rejects when neither email nor domain is given', () => {
        expect(parse({ email: '', domain: '' }).success).toBe(false);
        expect(parse({ email: '   ', domain: '   ' }).success).toBe(false);
    });

    it('rejects an invalid email', () => {
        const result = parse({ email: 'not-an-email', domain: '' });
        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues.some((issue) => issue.path[0] === 'email')).toBe(true);
        }
    });
});

describe('buildBlacklistPayload', () => {
    it('maps empty strings to an empty payload', () => {
        expect(buildBlacklistPayload({ email: '', domain: '   ', note: '' })).toEqual({});
    });

    it('trims values', () => {
        expect(buildBlacklistPayload({ email: ' Spam@Example.COM ', note: ' x ' })).toEqual({
            email: 'Spam@Example.COM',
            note: 'x',
        });
    });
});

describe('buildAllocationPayload', () => {
    it('builds an all-mode payload', () => {
        expect(buildAllocationPayload('all')).toEqual({ mode: 'all' });
    });

    it('builds a first-mode payload with limit', () => {
        expect(buildAllocationPayload('first', 3)).toEqual({ mode: 'first', limit: 3 });
    });

    it('drops the limit for all-mode even when given', () => {
        expect(buildAllocationPayload('all', 9)).toEqual({ mode: 'all' });
    });
});
