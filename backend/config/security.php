<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Reverse proxies whose `X-Forwarded-*` headers are honoured. Caddy
    | terminates TLS in front of this backend (FastCGI), so a proxy entry is
    | mandatory: without it `$request->isSecure()` is always `false` (absolute
    | URLs degrade to `http://`) and every `$request->ip()`-keyed rate limiter
    | collapses all clients into the proxy's bucket.
    |
    | Comma-separated IPs or CIDR ranges. Unset (or empty) = loopback, which
    | covers the shipped docker-compose topology. `*` trusts the calling IP
    | (only safe if the proxy overwrites the headers itself).
    |
    | Read at BOOT time from the `booting` callback in `bootstrap/app.php`,
    | never at kernel-resolution time (see `TrustedProxyConfig`).
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Hosts (static part of the allow-list)
    |--------------------------------------------------------------------------
    |
    | Extra host patterns Symfony validates the `Host` header against, on top
    | of the hostnames owned by a mandant (`mandant_domains.hostname`) and the
    | built-in local defaults (`localhost`, `127.0.0.1`, `::1`; plus the
    | `^(.+\.)?test$` / `^(.+\.)?localhost$` dev wildcards, which are omitted in
    | `production`). Entries are regexes (Symfony wraps each in `{...}i`), so a
    | wildcard must be spelled `^(.+\.)?example\.com$`.
    |
    | Non-empty value REPLACES the built-in DEFAULTS (the dev wildcards) — the
    | mandant hostnames are still merged in. The loopback entries are ALWAYS
    | merged in, independently of this variable and of the environment: a
    | container `HEALTHCHECK` or LB probe hits `GET /up` from 127.0.0.1, and an
    | allow-list without it answers 400 and marks the container unhealthy.
    | Unset = the defaults described above.
    |
    | See the `trustHosts()` callback in `bootstrap/app.php`.
    |
    */

    'trusted_hosts' => env('TRUSTED_HOSTS'),

    /*
    |--------------------------------------------------------------------------
    | Require an Origin header on state-changing API requests
    |--------------------------------------------------------------------------
    |
    | `App\Http\Middleware\EnsureSameOrigin` rejects a state-changing request
    | whose `Origin` is foreign, `null` or missing — with one documented
    | exception: a request that carries no `Sec-Fetch-*` header either cannot
    | come from a browser page on another site, so it is treated as a
    | first-party non-browser API client (curl, a mobile app, the Playwright
    | `APIRequestContext` the E2E suite uses for setup calls).
    |
    | Set this to `true` to close that exception as well: every
    | `POST|PUT|PATCH|DELETE` on `/api/*` must then carry an `Origin`.
    |
    */

    'require_origin_header' => env('REQUIRE_ORIGIN_HEADER', false),

];
