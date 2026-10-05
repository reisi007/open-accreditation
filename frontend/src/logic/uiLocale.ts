import { i18n } from '@lingui/core';

/**
 * Which language the API should answer in — the one the UI is CURRENTLY showing.
 *
 * ## Why this exists
 *
 * The backend negotiates its response locale from `Accept-Language`
 * (`backend/app/Http/Middleware/SetRequestLocale.php`), because the admin mail
 * surfaces used to answer with hardcoded German literals that the UI showed
 * verbatim — so an admin on the `en` locale read a German success line. The
 * server can only answer in the right language if the client says which one it
 * wants, and this module is where that answer comes from.
 *
 * ## Why the BROWSER's header is not good enough
 *
 * A browser sends `Accept-Language` from its own UI language settings, and
 * Playwright's Chromium sends `en-US` regardless of what the page displays. So
 * without this module the backend would answer in the browser's language while
 * the page renders in the app's — the admin would read an English "E-mail was
 * queued again." inside an otherwise German page, and switching the app to
 * English via `LanguageSwitcher` would change nothing on the server. Setting the
 * header explicitly is what makes the in-app language switch actually reach the
 * API.
 *
 * ## One number, one place
 *
 * `DEFAULT_UI_LOCALE` is the same `'de'` the SPA activates in `I18nProvider` and
 * the backend falls back to (`SetRequestLocale::DEFAULT_LOCALE`). It lives here
 * because `api/client.ts` must not import `I18nProvider` — that module pulls in
 * both compiled catalogs and calls `i18n.activate()` at module scope, and the
 * transport is imported by code that runs before the provider does.
 */

/**
 * The locale the app boots in. Mirrors `i18n.activate(...)` in `I18nProvider` and
 * the Lingui `sourceLocale` in `lingui.config.ts`.
 */
export const DEFAULT_UI_LOCALE = 'de';

/**
 * The active Lingui locale, or the boot locale when none has been activated yet.
 *
 * `I18n` starts with `locale === ''` (measured on @lingui/core 6.7.0), so reading
 * it unguarded would put an EMPTY `Accept-Language` on the wire — which a server
 * is right to read as "no preference", and which would then silently fall back to
 * its own default instead of to the one this app boots with.
 *
 * Read at CALL time, never at module scope: `i18n.activate()` mutates the shared
 * singleton when `LanguageSwitcher` is used, and a value captured during import
 * would freeze at the boot locale for the whole session.
 */
export function activeUiLocale(): string {
    return i18n.locale === '' ? DEFAULT_UI_LOCALE : i18n.locale;
}
