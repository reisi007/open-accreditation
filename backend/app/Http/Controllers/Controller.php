<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Attach the JWT to an httpOnly cookie and return a JSON response. Mirrors
     * the portal's AuthController cookie pattern — the token never reaches
     * localStorage.
     *
     * SameSite is conditional: in production (non-local, HTTPS) the SPA and the
     * API are served from different origins, so `SameSite=Lax` would suppress
     * the cookie on cross-site requests and the user could never authenticate.
     * `SameSite=None` keeps it attached cross-site — it is only valid together
     * with `Secure`, which is why it is restricted to the non-local environment.
     * Local dev stays same-site (`Lax`) over plain HTTP, where `None`+`Secure`
     * would be rejected by the browser.
     */
    protected function respondWithToken(string $token): JsonResponse
    {
        $ttl = auth('api')->factory()->getTTL();
        $local = app()->environment('local');
        $secure = ! $local;
        $sameSite = $local ? 'Lax' : 'None';

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
