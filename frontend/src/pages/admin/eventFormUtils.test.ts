import { describe, expect, it } from 'vitest';
import type { Event } from '../../api/types';
import { buildEventPayload, createEventSchema, eventFormDefaults, type EventFormValues } from './eventFormUtils';

const baseValues: EventFormValues = {
    title: 'Heimspiel',
    team_id: '',
    date: '',
    venue_id: '',
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
                venue_id: '12',
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
            venue_id: 12,
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
        expect(payload.venue_id).toBeNull();
        expect(payload.competition).toBeNull();
        expect(payload.deadline_start).toBeNull();
        expect(payload.deadline_end).toBeNull();
        expect(payload.active).toBe(true);
    });

    it('sends null for a cleared venue and trims whitespace-only competition', () => {
        const payload = buildEventPayload(valuesWith({ venue_id: '', competition: ' ' }));

        expect(payload.venue_id).toBeNull();
        expect(payload.competition).toBeNull();
    });
});

describe('eventFormDefaults', () => {
    it('returns empty values with active default true for a new event', () => {
        expect(eventFormDefaults(null)).toEqual({
            title: '',
            team_id: '',
            date: '',
            venue_id: '',
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
            venue_id: 12,
            venue: { id: 12, name: 'Stadion Nord' },
            competition: null,
            deadline_start: '2026-08-01',
            deadline_end: '2026-08-20',
            active: false,
            team: { id: 4, name: 'Musterverein' },
        };

        const defaults = eventFormDefaults(event);

        expect(defaults.title).toBe('Heimspiel');
        expect(defaults.team_id).toBe('4');
        // The form carries the venue REFERENCE (W12), the combobox resolves
        // the name — so the string `venue` must not leak into the payload.
        expect(defaults.venue_id).toBe('12');
        expect(defaults.active).toBe(false);
        expect(defaults.competition).toBe('');
    });

    it('maps an event without a venue onto an empty reference', () => {
        const event: Event = {
            id: 12,
            mandant_id: 1,
            team_id: null,
            title: 'Auswärtsspiel',
            date: null,
            venue_id: null,
            venue: null,
            competition: null,
            deadline_start: null,
            deadline_end: null,
            active: true,
            team: null,
        };

        expect(eventFormDefaults(event).venue_id).toBe('');
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

    it('rejects an end before the start and reports it on the deadline_end field', () => {
        const schema = createEventSchema();

        const result = schema.safeParse(
            valuesWith({ deadline_start: '2026-08-20', deadline_end: '2026-08-01' }),
        );

        expect(result.success).toBe(false);
        if (!result.success) {
            // An object-level issue (path `[]`) would be keyed under the empty
            // string by `zodResolver` and never rendered by `EventForm`.
            expect(result.error.issues).toHaveLength(1);
            expect(result.error.issues[0].path).toEqual(['deadline_end']);
            expect(result.error.issues[0].message).toBe('Das Ende der Frist muss nach dem Beginn liegen.');
        }
    });

    it('accepts a single-sided deadline', () => {
        const schema = createEventSchema();

        expect(schema.safeParse(valuesWith({ deadline_start: '2026-08-20' })).success).toBe(true);
        expect(schema.safeParse(valuesWith({ deadline_end: '2026-08-01' })).success).toBe(true);
    });

    it('requires a title', () => {
        const schema = createEventSchema();

        const result = schema.safeParse(valuesWith({ title: '' }));

        expect(result.success).toBe(false);
    });
});
