<?php

namespace Tests\Support;

use PHPOpenSourceSaver\JWTAuth\Contracts\Providers\Storage;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;

/**
 * A JWT blacklist storage that fails with a {@see JWTException} — the one
 * exception type `JWTGuard::logout()` explicitly swallows
 * (`vendor/php-open-source-saver/jwt-auth/src/JWTGuard.php:219-223`, the
 * `catch (JWTException $e) {}` with the comment *"Proceed with the logout as
 * normal if we can't invalidate the token"*).
 *
 * `get()` returns null unconditionally, so the blacklist is provably empty
 * before, during and after the logout: whatever the route reports, it cannot
 * be reporting a real revocation.
 *
 * The behaviour is accepted risk **A6** — vendor code, deliberately not
 * patched in this repository. `JwtBlacklistSurvivesDeploymentTest` pins it so
 * that an upstream fix fails loudly here instead of silently invalidating the
 * risk register.
 */
class JwtExceptionFailingJwtStorage implements Storage
{
    public function add($key, $value, $minutes)
    {
        throw new JWTException('simulated blacklist failure');
    }

    public function forever($key, $value)
    {
        throw new JWTException('simulated blacklist failure');
    }

    public function get($key)
    {
        return null;
    }

    public function destroy($key)
    {
        return true;
    }

    public function flush()
    {
        return true;
    }
}
