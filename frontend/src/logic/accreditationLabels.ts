import type { I18n } from '@lingui/core';
import { t } from '@lingui/core/macro';
import type { AccreditationScope, ApplicationStatus } from '../api/types';

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

export function availabilityLabel(available: number, i18n: I18n): string {
    if (available > 0) {
        return i18n._(t`${available} Plätze frei`);
    }

    return i18n._(t`Warteliste`);
}
