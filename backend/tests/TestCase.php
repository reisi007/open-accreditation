<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Log a user in via the JWT guard and attach the token to the httpOnly
     * cookie for subsequent requests — mirrors how the SPA authenticates.
     */
    protected function actingAsApi(User $user): static
    {
        $token = auth('api')->login($user);

        return $this->withCookie(config('jwt.cookie_key_name'), $token);
    }
}
