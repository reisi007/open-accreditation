<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /**
     * Attach the JWT to an httpOnly, SameSite=Lax cookie and return a JSON
     * response. Mirrors the portal's AuthController cookie pattern — the token
     * never reaches localStorage.
     */
    protected function respondWithToken(string $token): JsonResponse
    {
        $ttl = auth('api')->factory()->getTTL();
        $secure = ! app()->environment('local');

        $cookie = cookie(
            config('jwt.cookie_key_name'),
            $token,
            $ttl,
            '/',
            null,
            $secure,
            true,
            false,
            'Lax',
        );

        return response()->json([
            'message' => 'Erfolgreich angemeldet.',
            'expires_in' => $ttl * 60,
        ])->withCookie($cookie);
    }
}
