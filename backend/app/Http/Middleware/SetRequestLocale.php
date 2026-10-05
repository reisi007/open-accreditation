<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;

/**
 * Negotiate the response locale from the request's `Accept-Language` header.
 *
 * ## What it exists for
 *
 * The `{message}` bodies of the admin mail surfaces used to be hardcoded German
 * literals (`AdminApplicationController`, `AdminSubApplicationController`,
 * `FailedMailController`), and the UI shows those bodies verbatim — deliberately,
 * because the endpoint only ORDERS a delivery job and a string of our own would
 * claim a relay the process never talked to
 * (`frontend/src/logic/serverActionMessage.ts`). An admin on the `en` locale
 * therefore read a German success line. The strings now live in `lang/{de,en}`,
 * and **this middleware is what makes them reachable**: without it `App`'s
 * locale stays `config('app.locale')` and the DE catalog is only ever read by
 * accident (the configured default), while `en` is unreachable at all.
 *
 * ## Why `Accept-Language` and not a bespoke header
 *
 * There was **no** existing locale convention in this codebase — measured, not
 * assumed: `App::setLocale`/`getLocale`/`Accept-Language` had zero hits in
 * `app/` before this middleware, and no header of our own carries a language.
 * `Accept-Language` is the HTTP standard for exactly this negotiation, is
 * understood by every client, and needs no client-side plumbing beyond the SPA
 * setting it (it does — `frontend/src/api/client.ts`).
 *
 * ## DE is the default, and that is a decision
 *
 * An absent, empty, or unsatisfiable header yields **German**, because German is
 * this product's source language: the SPA activates `de` at boot
 * (`I18nProvider.tsx`), the Lingui `sourceLocale` is `de`, and every other
 * `{message}` in the API is German. A client that says nothing gets the product
 * it was built for, not the framework's default.
 *
 * Note this is deliberately **not** `config('app.locale')`: that stays `en`
 * (`.env.example`), because it governs non-HTTP contexts — console commands and
 * queue workers, where there is no `Accept-Language` at all. Mail bodies are
 * hardcoded German and untouched by either value; see `lang/de/mails.php`.
 *
 * ## `q=0` is REMOVED from the list — the one place this does NOT defer to Symfony
 *
 * `Request::getPreferredLanguage()` is the framework's own helper and does the
 * q-sorting and the `de-DE` → `de` subtag reduction correctly. But it IGNORES
 * `q=0`, and `q=0` means "explicitly not acceptable": measured,
 * `Accept-Language: en;q=0` returns `en` from it. Shipping that would answer a
 * client in the one language it has just said it does not want — the case where
 * honouring the header is the difference between a correct and an actively wrong
 * answer. So the q=0 items are filtered out here before the remaining list is
 * handed to Symfony, which keeps the standard's sorting and adds only the part
 * the standard has and Symfony's helper drops.
 *
 * ### Removed is not vetoed — read this before citing the heading
 *
 * What the filter buys is exactly this much: a refused language is never
 * SELECTED. It is not a veto over the outcome. Measured through `resolve()` on
 * 2026-10-05: `en;q=0` → `de`, `en;q=0,de;q=1` → `de`, `de;q=0,en;q=1` → `en`,
 * and **`de;q=0` → `de`** — a list emptied by refusals falls back to
 * `DEFAULT_LOCALE` unconditionally, so a client that refuses German is still
 * answered in German. "A refused language is not used" is therefore the wrong
 * summary of this file; the sentence that fits the code is "a refused language
 * is not selected".
 *
 * ## `Vary: Accept-Language`
 *
 * The body now depends on the request's language, so any intermediary cache
 * must key on it — without this header a shared cache would hand the German
 * body to an English client, which is a correctness bug rather than a cosmetic
 * one. Set on every response this middleware touches, since negotiation is
 * global for the API group.
 *
 * ## Position
 *
 * Prepended to the `api` group: it must run before anything that could RENDER a
 * message, and it is a no-op for the `web` group (which serves exactly one
 * static view) and for console/queue processes, which never pass through HTTP
 * middleware at all.
 */
class SetRequestLocale
{
    /**
     * The locales this application ships catalogs for. Keep in sync with
     * `frontend/lingui.config.ts` — the SPA and the API must offer the same set,
     * or one of them will negotiate a language the other cannot answer.
     *
     * `Tests\Feature\ServerMessageLocaleTest::`
     * `test_every_supported_locale_has_a_catalog_on_disk`
     * (backend/tests/Feature/ServerMessageLocaleTest.php:444) asserts the catalogs
     * on disk exist for exactly these locales, so a locale added here without its
     * `lang/<locale>/mails.php` fails there rather than in production.
     *
     * @var list<string>
     */
    public const SUPPORTED_LOCALES = ['de', 'en'];

    /**
     * Used when the header is absent, empty, lists only `q=0` languages, or
     * names nothing we ship. See the class docblock for why German.
     */
    public const DEFAULT_LOCALE = 'de';

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request->header('Accept-Language')));

        $response = $next($request);

        $this->varyOnLanguage($response);

        return $response;
    }

    /**
     * Add `Accept-Language` to `Vary` without dropping what is already there.
     *
     * `ResponseHeaderBag::set('Vary', …)` REPLACES the field, so a plain `set`
     * would silently drop an `Accept-Encoding`/`Origin` a downstream handler
     * already negotiated. The union is what makes the header mean "this
     * response varies by all of these", which is the only reading a cache has.
     */
    private function varyOnLanguage(Response $response): void
    {
        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary', ''))));

        $response->headers->set('Vary', implode(', ', array_unique([...$vary, 'Accept-Language'])));
    }

    /**
     * The best supported locale for this request.
     *
     * @param  string|null  $acceptLanguage  Raw header value; null/'' when absent.
     */
    public function resolve(?string $acceptLanguage): string
    {
        $acceptable = $this->acceptableLanguages($acceptLanguage);

        if ($acceptable === []) {
            return self::DEFAULT_LOCALE;
        }

        // Symfony does the q-sorting and the region→language reduction
        // (`de-DE` → `de`) per RFC 4647; we only narrowed the list to the
        // languages the client did not refuse.
        $request = Request::create('/');
        $request->headers->set('Accept-Language', implode(',', $acceptable));

        return $request->getPreferredLanguage(self::SUPPORTED_LOCALES)
            ?? self::DEFAULT_LOCALE;
    }

    /**
     * The header's languages in the client's own preference order, minus the
     * ones it explicitly refused (`q=0`).
     *
     * `AcceptHeader::all()` returns the items already sorted by descending
     * quality, with the original header order preserved for equal weights
     * (`uasort` on `getQuality()` then `getIndex()`), which is the tie-break
     * RFC 9110 asks for.
     *
     * @return list<string>
     */
    private function acceptableLanguages(?string $acceptLanguage): array
    {
        if ($acceptLanguage === null || trim($acceptLanguage) === '') {
            return [];
        }

        $languages = [];

        foreach (AcceptHeader::fromString($acceptLanguage)->all() as $item) {
            // `q=0` is "not acceptable", not "lowest preference": dropping the
            // item takes it out of the CANDIDATE list, which is what makes
            // `en;q=0` fall through to German. It vetoes nothing — an emptied
            // list still yields DEFAULT_LOCALE, so `de;q=0` resolves to `de`
            // (measured; the class docblock spells the difference out).
            if ($item->getQuality() <= 0.0) {
                continue;
            }

            $value = trim($item->getValue());

            if ($value !== '') {
                $languages[] = $value;
            }
        }

        return $languages;
    }
}
