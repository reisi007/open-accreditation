import { i18n } from '@lingui/core';
import { describe, expect, it } from 'vitest';
import { ApiError } from '../../api/client';
import { resendMailErrorMessage } from './resendMailUtils';

describe('resendMailErrorMessage', () => {
    it('maps a 422 (no mailable status/reason) to a localized message', () => {
        expect(resendMailErrorMessage(new ApiError(422, 'Application has no mailable status.', {}), i18n)).toBe(
            'Für diesen Antrag kann keine E-Mail gesendet werden.',
        );
    });

    it('maps a 403 (foreign team scope) to a localized message', () => {
        expect(resendMailErrorMessage(new ApiError(403, 'Forbidden', {}), i18n)).toBe(
            'Keine Berechtigung für diesen Antrag.',
        );
    });

    it('keeps field errors from the error info', () => {
        const error = new ApiError(422, 'Validation failed', { errors: { reason: ['Begründung fehlt.'] } });

        expect(resendMailErrorMessage(error, i18n)).toBe('Begründung fehlt.');
    });

    it('keeps the message of other ApiError failures (e.g. network)', () => {
        expect(resendMailErrorMessage(new ApiError(0, 'Netzwerkfehler: Keine Verbindung zum Server.', {}), i18n)).toBe(
            'Netzwerkfehler: Keine Verbindung zum Server.',
        );
    });

    it('falls back for unknown errors', () => {
        expect(resendMailErrorMessage(new Error('boom'), i18n)).toBe('E-Mail konnte nicht gesendet werden.');
    });
});
