import { describe, expect, it } from 'vitest';
import type { Venue } from '../../api/types';
import { createVenueSchema, venueFormDefaults, type VenueFormValues } from './venueFormUtils';

const venue: Venue = {
    id: 4,
    name: 'Stadion Nord',
    is_active: false,
    teams_count: 2,
    events_count: 7,
    created_at: '2026-09-01T10:00:00Z',
    updated_at: '2026-09-02T10:00:00Z',
};

describe('createVenueSchema', () => {
    it('accepts a name', () => {
        expect(createVenueSchema().safeParse({ name: 'Arena Süd' }).success).toBe(true);
    });

    it('rejects an empty name with a field-level message', () => {
        const result = createVenueSchema().safeParse({ name: '' });

        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues[0].path).toEqual(['name']);
            expect(result.error.issues[0].message).toBe('Name ist erforderlich.');
        }
    });

    it('caps the name length', () => {
        const result = createVenueSchema().safeParse({ name: 'a'.repeat(121) });

        expect(result.success).toBe(false);
        if (!result.success) {
            expect(result.error.issues[0].message).toBe('Name darf höchstens 120 Zeichen lang sein.');
        }
    });
});

describe('venueFormDefaults', () => {
    it('starts empty for a new venue', () => {
        expect(venueFormDefaults(null)).toEqual({ name: '' } satisfies VenueFormValues);
    });

    it('carries the current name for a rename', () => {
        expect(venueFormDefaults(venue)).toEqual({ name: 'Stadion Nord' });
    });
});
