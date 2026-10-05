import { i18n } from '@lingui/core';
import { afterEach, describe, expect, it } from 'vitest';
import { serverActionMessage } from './serverActionMessage';
// Side effect: activates the German catalog on the shared i18n instance.
import './I18nProvider';

/**
 * The two server bodies, MEASURED on the running backend (2026-10-02 and
 * re-measured 2026-10-05 in `backend/lang/{de,en}/mails.php`):
 *
 *   POST /api/admin/applications/{id}/resend      (approved + denied)
 *   POST /api/admin/sub-applications/{id}/resend  (approved + denied)
 *   POST /api/admin/failed-mails/{id}/requeue
 *
 *   de → "E-Mail wurde erneut in die Warteschlange gestellt."
 *   en → "E-mail was queued again."
 *
 * The `en` wording arrived with the backend localization (2026-10-05): the server
 * negotiates from `Accept-Language`, which `api/client.ts` sets to the locale
 * the UI is showing (`logic/uiLocale.ts`).
 */
const DE_MESSAGE = 'E-Mail wurde erneut in die Warteschlange gestellt.';
const EN_MESSAGE = 'E-mail was queued again.';

/**
 * `i18n` is the process-wide singleton, so each case restores it — otherwise the
 * `en` cases below would leak into every later test file in the same worker.
 */
afterEach(() => {
    i18n.activate('de');
});

describe('serverActionMessage', () => {
    it('shows the SERVER wording verbatim', () => {
        // Not a translated string of our own: the endpoint only ORDERS a
        // delivery job, so "wurde erneut gesendet" was a claim about a relay the
        // process never talked to.
        expect(serverActionMessage(DE_MESSAGE, i18n)).toBe(DE_MESSAGE);
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

    /**
     * ## Per locale, the server's words — in both languages
     *
     * This is the invariant the 2026-10-05 localization had to preserve. The
     * server now answers in the negotiated language, and the UI shows that
     * answer VERBATIM — so the English reader gets the server's English, not a
     * German string this module failed to translate, and (the other half, the
     * one that would be a real defect) not a sentence invented here either.
     *
     * The negative assertion is the load-bearing one: a "translate the German
     * server string in the UI" implementation would pass the first check in this
     * test and fail the second.
     */
    it('shows the server wording verbatim in the ACTIVE locale, and never invents one', () => {
        i18n.activate('de');
        expect(serverActionMessage(DE_MESSAGE, i18n)).toBe(DE_MESSAGE);

        i18n.activate('en');
        const shown = serverActionMessage(EN_MESSAGE, i18n);

        expect(shown).toBe(EN_MESSAGE);
        expect(shown).not.toContain('gesendet');
        // A German string reaching an English reader is the defect being fixed.
        expect(shown).not.toContain('E-Mail wurde');
    });

    /**
     * The one string this module owns is the FALLBACK, and it is ours — so it
     * must be localized. Pinned per locale because a German-only fallback would
     * be the same gap one branch deeper.
     */
    it('localizes its own fallback per active locale', () => {
        i18n.activate('de');
        expect(serverActionMessage('', i18n)).toBe('Zustellauftrag angenommen.');

        i18n.activate('en');
        const fallback = serverActionMessage('', i18n);

        expect(fallback).toBe('Delivery job accepted.');
        expect(fallback).not.toBe('Zustellauftrag angenommen.');
        // Same truthfulness budget in English: accepted, not delivered.
        expect(fallback).not.toMatch(/\bsent\b/i);
    });
});
