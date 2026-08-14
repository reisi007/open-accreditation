import type { I18n } from '@lingui/core';
import { t } from '@lingui/core/macro';
import { ApiError } from '../../api/client';

/**
 * Localize an error raised by POST /api/admin/applications/{id}/resend.
 *
 * The backend answers 422/403 with English `{message}` bodies, so those
 * statuses are mapped to localized strings here. Field errors (unexpected on
 * this endpoint) and other ApiError messages keep the existing ApiError
 * handling; network failures and unknown errors fall back to a generic
 * localized message.
 */
export function resendMailErrorMessage(err: unknown, i18n: I18n): string {
    if (err instanceof ApiError) {
        const first = Object.values(err.info.errors ?? {})
            .flat()
            .find((entry): entry is string => typeof entry === 'string' && entry !== '');
        if (first !== undefined) {
            return first;
        }
        if (err.status === 422) {
            return i18n._(t`Für diesen Antrag kann keine E-Mail gesendet werden.`);
        }
        if (err.status === 403) {
            return i18n._(t`Keine Berechtigung für diesen Antrag.`);
        }
        if (err.message !== '') {
            return err.message;
        }
    }

    return i18n._(t`E-Mail konnte nicht gesendet werden.`);
}
