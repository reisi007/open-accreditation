import { i18n } from '@lingui/core';
import { describe, expect, it } from 'vitest';
import { serverActionMessage } from './serverActionMessage';
// Side effect: activates the German catalog on the shared i18n instance.
import './I18nProvider';

/**
 * The message MEASURED on the running backend (2026-10-02) for
 * `POST /api/admin/applications/{id}/resend` and
 * `POST /api/admin/failed-mails/{id}/requeue`:
 * `"E-Mail wurde erneut in die Warteschlange gestellt."`
 */
const SERVER_MESSAGE = 'E-Mail wurde erneut in die Warteschlange gestellt.';

describe('serverActionMessage', () => {
    it('shows the SERVER wording verbatim', () => {
        // Not a translated string of our own: the endpoint only ORDERS a
        // delivery job, so "wurde erneut gesendet" was a claim about a relay the
        // process never talked to.
        expect(serverActionMessage(SERVER_MESSAGE, i18n)).toBe(SERVER_MESSAGE);
    });

    it('never invents the wording the server refused to make', () => {
        expect(serverActionMessage('', i18n)).not.toContain('gesendet');
        expect(serverActionMessage('   ', i18n)).not.toContain('gesendet');
        expect(serverActionMessage('', i18n)).toBe('Zustellauftrag angenommen.');
    });

    it('treats a whitespace-only server message as no message', () => {
        // `''` and `'  '` are the same absence here; trimming first is what makes
        // a blank success region impossible.
        expect(serverActionMessage('  \n ', i18n)).toBe('Zustellauftrag angenommen.');
    });
});
