import type { I18n } from '@lingui/core';
import { msg, t } from '@lingui/core/macro';
import type { AccreditationScope, ApplicationStatus, SubType } from '../api/types';

export function accreditationScopeLabel(scope: AccreditationScope, i18n: I18n): string {
    switch (scope) {
        case 'event':
            return i18n._(t`Spiel`);
        case 'league':
            return i18n._(t`Liga`);
        case 'season':
            return i18n._(t`Saison`);
    }
}

export function applicationStatusLabel(status: ApplicationStatus, i18n: I18n): string {
    switch (status) {
        case 'requested':
            return i18n._(t`Beantragt`);
        case 'approved':
            return i18n._(t`Freigegeben`);
        case 'denied':
            return i18n._(t`Abgelehnt`);
        case 'blacklisted':
            return i18n._(t`Gesperrt`);
    }
}

/**
 * The plural count MUST be passed as an explicit `values` entry: the `t` macro
 * only injects values for `${…}` template placeholders, never for a named ICU
 * argument. Without it the message renders as "NaN Platz(e) frei" (the same
 * blind spot that hit `DeadlineCountdown.tsx` — `tsc`/`eslint`/`vite build` and
 * the msgid-only `check-i18n` cannot see it). Pattern of reference:
 * `components/DeadlineCountdown.tsx`.
 */
export function availabilityLabel(available: number, i18n: I18n): string {
    if (available > 0) {
        return i18n._({ ...msg`{available, plural, one {# Platz frei} other {# Plätze frei}}`, values: { available } });
    }

    return i18n._(t`Warteliste`);
}

export function subTypeLabel(type: SubType, i18n: I18n): string {
    switch (type) {
        case 'park':
            return i18n._(t`Parkkarte`);
        case 'seat':
            return i18n._(t`Sitzkarte`);
    }
}

/** @see availabilityLabel — same `values` requirement, same reason. */
export function subAvailabilityLabel(available: number, i18n: I18n): string {
    if (available > 0) {
        return i18n._({ ...msg`Noch {available, plural, one {# Platz frei} other {# Plätze frei}}`, values: { available } });
    }

    return i18n._(t`Warteliste`);
}
