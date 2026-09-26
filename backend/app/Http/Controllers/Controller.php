<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

abstract class Controller
{
    /**
     * Attach the JWT to an httpOnly cookie and return a JSON response. Mirrors
     * the portal's AuthController cookie pattern — the token never reaches
     * localStorage.
     *
     * SameSite: the SPA and the API share ONE origin. The SPA calls the API
     * with relative paths (`fetch('/api/…')` in `frontend/src/api/client.ts`)
     * and Caddy routes `/api*` to this backend inside the very same
     * per-mandant site block (`deployment/caddy-media-api-accel.Caddyfile`).
     * `SameSite=Lax` is therefore sufficient — and it is the default in EVERY
     * environment, including production. (The old `SameSite=None`-outside-
     * `local` rule was based on a cross-site SPA+API split that does not
     * exist; it disabled the SameSite half of the CSRF defence for every real
     * deployment.)
     *
     * `SameSite=None` (which drops the CSRF-relevant SameSite protection)
     * stays available for a genuinely cross-site deployment, opted in
     * explicitly with `JWT_CROSS_SITE_COOKIE=true`
     * (`config('jwt.cross_site_cookie')`). It is never derived from the
     * environment, so a `staging`/split deployment cannot silently lose it.
     * `App\Http\Middleware\EnsureSameOrigin` is the matching second layer.
     *
     * `SameSite=None` REQUIRES `Secure`: Chrome ≥ 84 / Firefox ≥ 96 reject a
     * `SameSite=None` cookie without it outright, which would break every login
     * silently. The two attributes are therefore not independent here:
     *
     *   - opt-in off (the default): `SameSite=Lax`, `Secure = ! local`, so the
     *     dev servers on plain HTTP still get a cookie.
     *   - opt-in on: `SameSite=None` + `Secure` — and `local` is REFUSED
     *     (loud `RuntimeException` + `Log::critical`) instead of emitting a
     *     cookie no browser would accept or a `Secure` cookie every browser
     *     would drop on `http://localhost`. A cross-site deployment is a
     *     TLS-terminated deployment by definition.
     */
    protected function respondWithToken(string $token): JsonResponse
    {
        $ttl = auth('api')->factory()->getTTL();
        $local = app()->environment('local');
        $crossSite = (bool) config('jwt.cross_site_cookie');

        if ($crossSite && $local) {
            Log::critical('JWT_CROSS_SITE_COOKIE=true is incompatible with the local (plain HTTP) environment: SameSite=None requires Secure, and browsers drop a Secure cookie on http://. Refusing to emit an auth cookie that no client would accept.', [
                'app_env' => app()->environment(),
            ]);

            throw new RuntimeException(
                'JWT_CROSS_SITE_COOKIE=true requires an HTTPS environment. Remove the flag (the SPA and the API are served from one origin) or serve the local environment over TLS.'
            );
        }

        $sameSite = $crossSite ? 'None' : 'Lax';
        // Outside `local` the app is TLS-terminated, so `Secure` always holds —
        // and with `SameSite=None` it is mandatory, not a preference.
        $secure = ! $local;

        $cookie = cookie(
            config('jwt.cookie_key_name'),
            $token,
            $ttl,
            '/',
            null,
            $secure,
            true,
            false,
            $sameSite,
        );

        return response()->json([
            'message' => 'Erfolgreich angemeldet.',
            'expires_in' => $ttl * 60,
        ])->withCookie($cookie);
    }
}
