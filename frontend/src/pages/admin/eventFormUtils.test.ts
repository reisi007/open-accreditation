import { describe, expect, it } from 'vitest';
import type { Event } from '../../api/types';
import { buildEventPayload, createEventSchema, eventFormDefaults, type EventFormValues } from './eventFormUtils';

const baseValues: EventFormValues = {
    title: 'Heimspiel',
    team_id: '',
    date: '',
    venue: '',
    competition: '',
    deadline_start: '',
    deadline_end: '',
    active: true,
};

function valuesWith(overrides: Partial<EventFormValues>): EventFormValues {
    return { ...baseValues, ...overrides };
}

describe('buildEventPayload', () => {
    it('maps the form fields onto the API payload', () => {
        const payload = buildEventPayload(
            valuesWith({
                team_id: '7',
                date: '2026-09-01',
                venue: '  Stadion Nord  ',
                competition: 'Pokal',
                deadline_start: '2026-08-01',
                deadline_end: '2026-08-20',
                active: false,
            }),
        );

        expect(payload).toEqual({
            title: 'Heimspiel',
            team_id: 7,
            date: '2026-09-01',
            venue: 'Stadion Nord',
            competition: 'Pokal',
            deadline_start: '2026-08-01',
            deadline_end: '2026-08-20',
            active: false,
        });
    });

    it('sends null for empty optional fields and the mandant level', () => {
        const payload = buildEventPayload(baseValues);

        expect(payload.team_id).toBeNull();
        expect(payload.date).toBeNull();
        expect(payload.venue).toBeNull();
        expect(payload.competition).toBeNull();
        expect(payload.deadline_start).toBeNull();
        expect(payload.deadline_end).toBeNull();
        expect(payload.active).toBe(true);
    });

    it('trims whitespace-only venue and competition to null', () => {
        const payload = buildEventPayload(valuesWith({ venue: '   ', competition: ' ' }));

        expect(payload.venue).toBeNull();
        expect(payload.competition).toBeNull();
    });
});

describe('eventFormDefaults', () => {
    it('returns empty values with active default true for a new event', () => {
        expect(eventFormDefaults(null)).toEqual({
            title: '',
            team_id: '',
            date: '',
            venue: '',
            competition: '',
            deadline_start: '',
            deadline_end: '',
            active: true,
        });
    });

    it('maps a stored event onto the form fields', () => {
        const event: Event = {
            id: 11,
            mandant_id: 1,
            team_id: 4,
            title: 'Heimspiel',
            date: '2026-09-01',
            venue: 'Stadion Nord',
            competition: null,
            deadline_start: '2026-08-01',
            deadline_end: '2026-08-20',
            active: false,
            team: { id: 4, name: 'Musterverein' },
        };

        const defaults = eventFormDefaults(event);

        expect(defaults.title).toBe('Heimspiel');
        expect(defaults.team_id).toBe('4');
        expect(defaults.active).toBe(false);
        expect(defaults.competition).toBe('');
    });
});

describe('createEventSchema', () => {
    it('accepts equal deadline dates', () => {
        const schema = createEventSchema();

        const result = schema.safeParse(
            valuesWith({ deadline_start: '2026-08-01', deadline_end: '2026-08-01' }),
        );

        expect(result.success).toBe(true);
    });

    it('accepts an end after the start', () => {
        const schema = createEventSchema();

        const result = schema.safeParse(
            valuesWith({ deadline_start: '2026-08-01', deadline_end: '2026-08-20' }),
        );

        expect(result.success).toBe(true);
    });

    it('rejects an end before the start', () => {
        const schema = createEventSchema();

        const result = schema.safeParse(
            valuesWith({ deadline_start: '2026-08-20', deadline_end: '2026-08-01' }),
        );

        expect(result.success).toBe(false);
    });

    it('requires a title', () => {
        const schema = createEventSchema();

        const result = schema.safeParse(valuesWith({ title: '' }));

        expect(result.success).toBe(false);
    });
});
