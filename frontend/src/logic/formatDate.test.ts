import { describe, expect, it } from 'vitest';
import { formatDate, formatDateTime } from './formatDate';

describe('formatDate', () => {
    it('formats a plain date string in the given locale', () => {
        // Pinned on the German form: the day/month ORDER is what the locale
        // decides, and a silent switch to `en` would read as a data bug.
        expect(formatDate('2026-10-02', 'de')).toBe('2. Oktober 2026');
    });

    it('returns the raw value when it is not a date', () => {
        expect(formatDate('kein Datum', 'de')).toBe('kein Datum');
    });
});

describe('formatDateTime', () => {
    it('formats an ISO timestamp with its time', () => {
        const formatted = formatDateTime('2026-10-02T09:30:00+00:00', 'de');
        expect(formatted).toContain('2026');
        expect(formatted).toMatch(/\d{2}:\d{2}/);
    });

    it('returns the raw value when the timestamp cannot be parsed', () => {
        expect(formatDateTime('nicht parsebar', 'de')).toBe('nicht parsebar');
    });
});
