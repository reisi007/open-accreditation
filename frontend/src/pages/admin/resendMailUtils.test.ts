import { i18n } from '@lingui/core';
import { describe, expect, it } from 'vitest';
import { ApiError } from '../../api/client';
import { resendMailErrorMessage } from './resendMailUtils';

describe('resendMailErrorMessage', () => {
    // The bodies fed in below are the REAL ones, byte for byte, and BOTH locales
    // are offered per case because that is what the endpoints answer depending
    // on `Accept-Language` — the mapping has to be blind to which one it got.
    //
    //  - **422** carries a CATALOG string, `__('mails.*')` from
    //    `backend/lang/{de,en}/mails.php` (German by default, English wherever
    //    the header negotiates `en`). The English literals these tests used to
    //    feed in are what the controllers answered BEFORE the catalogs landed:
    //    no longer producible, and keeping them left the test asserting against
    //    a body the server can no longer send.
    //  - **403** carries NO message at all — `abort_unless($query->exists(), 403)`
    //    without message text, which the exception handler renders as
    //    `{"message": ""}`. `'Forbidden'` was never this endpoint's body.
    //
    // Both facts, with the controller line numbers, are stated in
    // `resendMailUtils`' own docblock.
    it.each([
        ['de', 'Für diesen Antrag gibt es keinen versendbaren Status.'],
        ['en', 'This application has no mailable status.'],
    ])('maps a 422 (no mailable status) from the %s catalog to a localized message', (locale, body) => {
        expect(resendMailErrorMessage(new ApiError(422, body, {}), i18n), `locale ${locale}`).toBe(
            'Für diesen Antrag kann keine E-Mail gesendet werden.',
        );
    });

    it('maps a 403 (foreign team scope) to a localized message', () => {
        expect(resendMailErrorMessage(new ApiError(403, '', {}), i18n)).toBe('Keine Berechtigung für diesen Antrag.');
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

    it.each([
        ['de', 'Für diesen Sub-Antrag gibt es keinen versendbaren Status.'],
        ['en', 'This sub-application has no mailable status.'],
    ])(
        'names the SUB-application in the 422 message, not the main one (%s catalog)',
        // The sub-row lives in the "Sub-Anträge" table; borrowing the main
        // request's sentence there would be true only by accident.
        (locale, body) => {
            expect(resendMailErrorMessage(new ApiError(422, body, {}), i18n, 'subApplication'), `locale ${locale}`).toBe(
                'Für diesen Sub-Antrag kann keine E-Mail gesendet werden.',
            );
        },
    );

    it('names the SUB-application in the 403 message, not the main one', () => {
        expect(resendMailErrorMessage(new ApiError(403, '', {}), i18n, 'subApplication')).toBe(
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
