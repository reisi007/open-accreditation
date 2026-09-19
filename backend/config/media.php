<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Internal Accel Delivery (W11)
    |--------------------------------------------------------------------------
    |
    | When `MEDIA_ACCEL_PREFIX` is a non-empty string, public media delivery
    | endpoints answer with an empty 200 + `X-Accel-Redirect:
    | <prefix>/<media-relative-path>` instead of streaming the file through
    | PHP. Caddy intercepts the header (`handle_response @accel_header`) and
    | serves the file directly from MEDIA_ROOT.
    |
    | DEFAULT IS OFF (empty): the backend keeps streaming through
    | `Storage::response`, so the flag and the Caddy snippet can never get out
    | of step into a bodyless response (W11 risk R1). Enable it only together
    | with the `(media_api_accel)` snippet in the proxy Caddyfile.
    |
    | The value is an internal URL prefix, normalised to `/…` without a
    | trailing slash (e.g. `/__media`). Caddy's `import media_api_accel
    | <MEDIA_ROOT> <prefix>` must use the identical prefix.
    |
    | WebP negotiation: when the client sends `Accept: image/webp` and a `.webp`
    | sibling exists, the sibling is served instead of the original. No
    | `Vary: Accept` header is emitted — the canonical URL is the DB path and
    | these API responses are per-request (auth/portal, per host), not a shared
    | content-negotiated cache that `Vary` would need to keep correct.
    |
    */

    'accel_prefix' => env('MEDIA_ACCEL_PREFIX', ''),

];
