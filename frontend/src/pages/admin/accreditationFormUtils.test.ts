import { describe, expect, it } from 'vitest';
import {
    accreditationFormDefaults,
    buildAccreditationPayload,
    createAccreditationSchema,
    type AccreditationFormValues,
} from './accreditationFormUtils';

const validValues: AccreditationFormValues = {
    category_id: '1',
    scope: 'event',
    event_id: '2',
    team_id: '',
    quota: 5,
    deadline_start: '2026-08-01',
    deadline_end: '2026-08-20',
    auto_approve: false,
    active: true,
};

function parse(values: AccreditationFormValues) {
    return createAccreditationSchema().safeParse(values);
}

describe('createAccreditationSchema', () => {
    it('accepts a complete event-scoped accreditation', () => {
        expect(parse(validValues).success).toBe(true);
    });

    it('requires an event when the scope is "event"', () => {
        const result = parse({ ...validValues, event_id: '' });
        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues.some((issue) => issue.path[0] === 'event_id')).toBe(true);
        }
    });

    it('allows an empty event for league scope', () => {
        expect(parse({ ...validValues, scope: 'league', event_id: '' }).success).toBe(true);
    });

    it('allows an empty event for season scope', () => {
        expect(parse({ ...validValues, scope: 'season', event_id: '' }).success).toBe(true);
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

    it('rejects a quota below 1', () => {
        expect(parse({ ...validValues, quota: 0 }).success).toBe(false);
    });

    it('rejects an empty quota', () => {
        const result = createAccreditationSchema().safeParse({ ...validValues, quota: '' });
        expect(result.success).toBe(false);
    });

    it('requires a category', () => {
        expect(parse({ ...validValues, category_id: '' }).success).toBe(false);
    });
});

describe('buildAccreditationPayload', () => {
    it('sends the numeric event_id for event scope', () => {
        const payload = buildAccreditationPayload(validValues, null);
        expect(payload.category_id).toBe(1);
        expect(payload.event_id).toBe(2);
        expect(payload.team_id).toBeNull();
        expect(payload.quota).toBe(5);
        expect(payload.deadline_start).toBe('2026-08-01');
        expect(payload.deadline_end).toBe('2026-08-20');
        expect(payload.auto_approve).toBe(false);
        expect(payload.active).toBe(true);
    });

    it('sends null event_id for league/season scope', () => {
        const payload = buildAccreditationPayload({ ...validValues, scope: 'league', event_id: '' }, null);
        expect(payload.scope).toBe('league');
        expect(payload.event_id).toBeNull();
    });

    it('sends null team_id for an empty team select', () => {
        const payload = buildAccreditationPayload(validValues, null);
        expect(payload.team_id).toBeNull();
    });

    it('forces the team_admin team id', () => {
        const payload = buildAccreditationPayload({ ...validValues, team_id: '9' }, 42);
        expect(payload.team_id).toBe(42);
    });

    it('maps empty deadlines to null', () => {
        const payload = buildAccreditationPayload({ ...validValues, deadline_start: '', deadline_end: '' }, null);
        expect(payload.deadline_start).toBeNull();
        expect(payload.deadline_end).toBeNull();
    });
});

describe('accreditationFormDefaults', () => {
    it('returns defaults for a new accreditation', () => {
        const defaults = accreditationFormDefaults(null, null);
        expect(defaults.category_id).toBe('');
        expect(defaults.scope).toBe('event');
        expect(defaults.event_id).toBe('');
        expect(defaults.team_id).toBe('');
        expect(defaults.quota).toBe(1);
        expect(defaults.auto_approve).toBe(false);
        expect(defaults.active).toBe(true);
    });

    it('maps the initial accreditation fields', () => {
        const initial = {
            id: 7,
            category_id: 3,
            category: { id: 3, name: 'Presse' },
            scope: 'season' as const,
            event_id: null,
            event: null,
            team_id: 4,
            team: { id: 4, name: 'Heimverein' },
            quota: 10,
            applications_count: 2,
            available: 8,
            deadline_start: '2026-01-01',
            deadline_end: '2026-02-01',
            auto_approve: true,
            active: false,
        };
        const defaults = accreditationFormDefaults(initial, null);
        expect(defaults.category_id).toBe('3');
        expect(defaults.scope).toBe('season');
        expect(defaults.event_id).toBe('');
        expect(defaults.team_id).toBe('4');
        expect(defaults.quota).toBe(10);
        expect(defaults.auto_approve).toBe(true);
        expect(defaults.active).toBe(false);
    });

    it('forces the team_admin team id for a new accreditation', () => {
        const defaults = accreditationFormDefaults(null, 8);
        expect(defaults.team_id).toBe('8');
    });
});
