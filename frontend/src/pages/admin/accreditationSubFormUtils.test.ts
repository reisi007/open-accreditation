import { describe, expect, it } from 'vitest';
import {
    buildSubAccreditationPayload,
    createSubAccreditationSchema,
    subAccreditationFormDefaults,
    type SubAccreditationFormValues,
} from './accreditationSubFormUtils';

const validValues: SubAccreditationFormValues = {
    type: 'park',
    quota: 5,
    deadline_start: '2026-08-01',
    deadline_end: '2026-08-20',
    auto_approve: false,
    active: true,
};

function parse(values: SubAccreditationFormValues) {
    return createSubAccreditationSchema().safeParse(values);
}

describe('createSubAccreditationSchema', () => {
    it('accepts a complete park sub-accreditation', () => {
        expect(parse(validValues).success).toBe(true);
    });

    it('accepts a seat type', () => {
        expect(parse({ ...validValues, type: 'seat' }).success).toBe(true);
    });

    it('rejects an unknown type', () => {
        const result = createSubAccreditationSchema().safeParse({ ...validValues, type: 'banana' });
        expect(result.success).toBe(false);
    });

    it('rejects a quota below 1', () => {
        expect(parse({ ...validValues, quota: 0 }).success).toBe(false);
    });

    it('rejects an empty quota', () => {
        const result = createSubAccreditationSchema().safeParse({ ...validValues, quota: '' });
        expect(result.success).toBe(false);
    });

    it('rejects a deadline_end before deadline_start', () => {
        const result = parse({ ...validValues, deadline_end: '2026-08-01', deadline_start: '2026-08-20' });
        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues.some((issue) => issue.path[0] === 'deadline_end')).toBe(true);
        }
    });

    it('allows equal start and end dates (single-day window)', () => {
        expect(parse({ ...validValues, deadline_end: '2026-08-20', deadline_start: '2026-08-20' }).success).toBe(true);
    });

    it('allows an empty deadline window', () => {
        expect(parse({ ...validValues, deadline_start: '', deadline_end: '' }).success).toBe(true);
    });
});

describe('buildSubAccreditationPayload', () => {
    it('sends the form values as-is', () => {
        const payload = buildSubAccreditationPayload(validValues);
        expect(payload).toEqual({
            type: 'park',
            quota: 5,
            deadline_start: '2026-08-01',
            deadline_end: '2026-08-20',
            auto_approve: false,
            active: true,
        });
    });

    it('maps empty deadlines to null', () => {
        const payload = buildSubAccreditationPayload({ ...validValues, deadline_start: '', deadline_end: '' });
        expect(payload.deadline_start).toBeNull();
        expect(payload.deadline_end).toBeNull();
    });

    it('passes through the seat type', () => {
        const payload = buildSubAccreditationPayload({ ...validValues, type: 'seat' });
        expect(payload.type).toBe('seat');
    });
});

describe('subAccreditationFormDefaults', () => {
    it('returns defaults for a new sub-accreditation', () => {
        const defaults = subAccreditationFormDefaults(null);
        expect(defaults.type).toBe('park');
        expect(defaults.quota).toBe(1);
        expect(defaults.deadline_start).toBe('');
        expect(defaults.deadline_end).toBe('');
        expect(defaults.auto_approve).toBe(false);
        expect(defaults.active).toBe(true);
    });

    it('maps the initial sub-accreditation fields', () => {
        const initial = {
            id: 9,
            accreditation_id: 3,
            type: 'seat' as const,
            quota: 10,
            applications_count: 2,
            available: 8,
            deadline_start: '2026-01-01',
            deadline_end: '2026-02-01',
            auto_approve: true,
            active: false,
        };
        const defaults = subAccreditationFormDefaults(initial);
        expect(defaults.type).toBe('seat');
        expect(defaults.quota).toBe(10);
        expect(defaults.deadline_start).toBe('2026-01-01');
        expect(defaults.deadline_end).toBe('2026-02-01');
        expect(defaults.auto_approve).toBe(true);
        expect(defaults.active).toBe(false);
    });
});
