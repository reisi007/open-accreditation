import { i18n } from '@lingui/core';
import { describe, expect, it } from 'vitest';
import {
    accreditationScopeLabel,
    applicationStatusLabel,
    availabilityLabel,
    subAvailabilityLabel,
    subTypeLabel,
} from './accreditationLabels';
// Side effect: loads and activates the German catalog on the shared i18n instance.
import './I18nProvider';

/**
 * The plural helpers must interpolate the count.
 *
 * The `t` macro from `@lingui/core/macro` injects `values` ONLY for `${…}`
 * template placeholders — never for a named ICU argument. Calling
 * `i18n._(t\`{available, plural, …}\`)` therefore leaves `available` unbound and
 * renders literally "NaN Platz frei" on the portal and sub-accreditation pages.
 *
 * `tsc`, `eslint`, `vite build` and the msgid-only `check-i18n` are all blind to
 * it, so the assertions below are deliberately exact (the rendered NUMBER, not
 * a `/^Noch \S+ …/` shape match): only an exact expectation fails when the
 * interpolation is lost. `tests/e2e/sub-accreditation.spec.ts:100` asserts
 * `/Noch 1 (Platz|Plätze) frei/` against the same real DOM.
 */
describe('availabilityLabel', () => {
    it('interpolates the count in the singular ICU form', () => {
        expect(availabilityLabel(1, i18n)).toBe('1 Platz frei');
    });

    it('interpolates the count in the plural ICU form', () => {
        expect(availabilityLabel(3, i18n)).toBe('3 Plätze frei');
    });

    it('falls back to the waiting list when nothing is free', () => {
        expect(availabilityLabel(0, i18n)).toBe('Warteliste');
        expect(availabilityLabel(-1, i18n)).toBe('Warteliste');
    });
});

describe('subAvailabilityLabel', () => {
    it('interpolates the count in the singular ICU form', () => {
        expect(subAvailabilityLabel(1, i18n)).toBe('Noch 1 Platz frei');
    });

    it('interpolates the count in the plural ICU form', () => {
        expect(subAvailabilityLabel(2, i18n)).toBe('Noch 2 Plätze frei');
    });

    it('falls back to the waiting list when nothing is free', () => {
        expect(subAvailabilityLabel(0, i18n)).toBe('Warteliste');
        expect(subAvailabilityLabel(-1, i18n)).toBe('Warteliste');
    });

    it('never renders the unbound-argument "NaN" placeholder', () => {
        for (const available of [1, 2, 3, 7, 42]) {
            expect(availabilityLabel(available, i18n)).not.toContain('NaN');
            expect(subAvailabilityLabel(available, i18n)).not.toContain('NaN');
        }
    });
});

describe('static label helpers', () => {
    it('maps every accreditation scope', () => {
        expect(accreditationScopeLabel('event', i18n)).toBe('Spiel');
        expect(accreditationScopeLabel('league', i18n)).toBe('Liga');
        expect(accreditationScopeLabel('season', i18n)).toBe('Saison');
    });

    it('maps every application status', () => {
        expect(applicationStatusLabel('requested', i18n)).toBe('Beantragt');
        expect(applicationStatusLabel('approved', i18n)).toBe('Freigegeben');
        expect(applicationStatusLabel('denied', i18n)).toBe('Abgelehnt');
        expect(applicationStatusLabel('blacklisted', i18n)).toBe('Gesperrt');
    });

    it('maps every sub-accreditation type', () => {
        expect(subTypeLabel('park', i18n)).toBe('Parkkarte');
        expect(subTypeLabel('seat', i18n)).toBe('Sitzkarte');
    });
});
