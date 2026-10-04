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

    it('names the SUB-application in the 422 message, not the main one', () => {
        // The sub-row lives in the "Sub-Anträge" table; borrowing the main
        // request's sentence there would be true only by accident.
        expect(
            resendMailErrorMessage(new ApiError(422, 'Sub-application has no mailable status.', {}), i18n, 'subApplication'),
        ).toBe('Für diesen Sub-Antrag kann keine E-Mail gesendet werden.');
    });

    it('names the SUB-application in the 403 message, not the main one', () => {
        expect(resendMailErrorMessage(new ApiError(403, 'Forbidden', {}), i18n, 'subApplication')).toBe(
            'Keine Berechtigung für diesen Sub-Antrag.',
        );
    });

    it('keeps the server message for a sub-application 404 (foreign mandant)', () => {
        // No mapping for 404 by design: the body's own words ("No query results
        // for model […]") are more truthful than any sentence of ours.
        expect(
            resendMailErrorMessage(
                new ApiError(404, 'No query results for model [App\\Models\\SubApplication] 999.', {}),
                i18n,
                'subApplication',
            ),
        ).toBe('No query results for model [App\\Models\\SubApplication] 999.');
    });

    it('falls back for an unknown sub-application error', () => {
        expect(resendMailErrorMessage(new Error('boom'), i18n, 'subApplication')).toBe('E-Mail konnte nicht gesendet werden.');
    });
});
