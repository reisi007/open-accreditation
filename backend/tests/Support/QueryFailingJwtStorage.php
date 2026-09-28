<?php

namespace Tests\Support;

use Illuminate\Database\QueryException;
use PHPOpenSourceSaver\JWTAuth\Contracts\Providers\Storage;

/**
 * A JWT blacklist storage whose write fails the way a MISSING TABLE would:
 * a hard {@see QueryException}, which `JWTGuard::logout()` does NOT swallow.
 *
 * Used by `JwtBlacklistSurvivesDeploymentTest` to establish the contrast that
 * makes the silently swallowed failure a *defect* rather than a curiosity: a
 * storage that fails loudly answers 500, one that fails with a
 * `JWTException` answers 200 "Erfolgreich abgemeldet." (see A6).
 */
class QueryFailingJwtStorage implements Storage
{
    public function add($key, $value, $minutes)
    {
        throw $this->failure();
    }

    public function forever($key, $value)
    {
        throw $this->failure();
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

    private function failure(): QueryException
    {
        return new QueryException(
            'sqlite',
            'insert into "jwt" ...',
            [],
            new \Exception('no such table: jwt')
        );
    }
}
