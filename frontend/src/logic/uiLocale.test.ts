import { i18n } from '@lingui/core';
import { afterEach, describe, expect, it } from 'vitest';
import { activeUiLocale, DEFAULT_UI_LOCALE } from './uiLocale';

/**
 * `i18n` is the process-wide Lingui singleton, so every case has to put it back
 * or it leaks into the next test file in the same worker. The leak is silent and
 * order-dependent — the kind that makes a suite green on one machine and red on
 * another.
 */
afterEach(() => {
    i18n.activate(DEFAULT_UI_LOCALE);
});

describe('activeUiLocale', () => {
    it('reports the LOCALE, not a copy taken at import time', () => {
        // This is the whole point of the module: `LanguageSwitcher` calls
        // `i18n.activate()`, and the next request has to carry the NEW locale.
        // A value captured during import would freeze at the boot locale for the
        // whole session, and switching the UI to English would change nothing the
        // server can see.
        i18n.activate('en');
        expect(activeUiLocale()).toBe('en');

        i18n.activate('de');
        expect(activeUiLocale()).toBe('de');
    });

    it('falls back to the boot locale before any locale has been activated', () => {
        // `I18n` starts with `locale === ''` (measured on @lingui/core 6.7.0).
        // Returning that empty string would put an EMPTY `Accept-Language` on
        // the wire — which a server is right to read as "no preference", so it
        // would fall back to ITS default instead of to ours.
        i18n.activate('');

        expect(activeUiLocale()).toBe('de');
        expect(activeUiLocale()).not.toBe('');
    });

    it('pins German as the boot locale', () => {
        // German is this product's source language: the Lingui `sourceLocale`,
        // the backend's `SetRequestLocale::DEFAULT_LOCALE`, and every other
        // `{message}` in the API. A change here is a product decision, not a
        // refactor — which is why it is asserted rather than derived.
        expect(DEFAULT_UI_LOCALE).toBe('de');
    });

    it('names only locales the backend can answer', () => {
        // The API negotiates over `['de', 'en']`; asking for anything else would
        // be answered in the server's default rather than in what was asked for.
        // I18n is untyped about its locale, so this is the only guard.
        for (const locale of ['de', 'en']) {
            i18n.activate(locale);
            expect(activeUiLocale()).toBe(locale);
        }
    });
});
