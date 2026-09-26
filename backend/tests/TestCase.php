<?php

namespace Tests;

use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Request;

abstract class TestCase extends BaseTestCase
{
    /**
     * The local disks that are rooted in the throwaway test tree for every
     * single test.
     *
     * `local` is the configured default disk (`FILESYSTEM_DISK=local`) and
     * shares its root with `private`; `media` is the public brand/team/badge
     * disk (W1). Without this list a test that forgets `Storage::fake()` writes
     * into `storage/app/private` / `storage/app/media` — the developer's REAL
     * dev media, gitignored and never cleaned. `public` completes the set of
     * local disks. `s3` is deliberately absent: no test touches it, and
     * faking it would silently turn a remote disk into a local one.
     */
    public const FAKE_DISKS = ['local', 'private', 'media', 'public'];

    /**
     * The root directory of every faked disk, captured while faking.
     *
     * Reading the root back off the installed driver (instead of recomputing
     * `storage_path('framework/testing/disks/<disk>')`) keeps this correct under
     * `paratest`, where `Storage::fake()` appends a per-process token to the
     * root — WP-11: the suite must be repeatable, not just green once.
     *
     * @var array<string, string>
     */
    private array $fakeDiskRoots = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rootEveryDiskInTheTestTree();
    }

    protected function tearDown(): void
    {
        // `TrustHosts::handle()` writes the resolved allow-list into Symfony's
        // STATIC `Request::$trustedHostPatterns`, and in tests that only
        // happens for tests which fake a non-console production run (the
        // middleware skips itself while `runningUnitTests()` is true). Without
        // this reset the patterns survive into every later test of the process
        // and every request is validated against a stale list — which used to
        // pass unnoticed because the list happened to contain the APP_URL
        // wildcard (`^(.+\.)?<APP_URL host>$`, local `APP_URL` in `.env`) and
        // therefore re-trusted the hosts all other tests use. With
        // `trustHosts(..., subdomains: false)` (WF-2-c) that accident is gone,
        // so the leak surfaces as `Untrusted Host` in unrelated tests.
        // `TrustHosts::flushState()` is deliberately NOT called: it would drop
        // the configured patterns and make `hosts()` fall back to the
        // framework default (`[^(.+\.)?<APP_URL host>$]`) — the very
        // wildcard this reset is about.
        Request::setTrustedHosts([]);

        $this->purgeFakeDiskRoots();

        parent::tearDown();
    }

    /**
     * Point every local disk at the throwaway test tree, starting empty.
     *
     * `Storage::fake()` IS Laravel's "fresh root" primitive — it cleans the
     * directory and installs a local driver rooted there — so this is exactly
     * the semantics `fake` is documented to have, just applied to every local
     * disk up front instead of per test class. Two things follow:
     *
     * 1. No test can write into `storage/app/*` (the real dev/prod media) by
     *    forgetting a `Storage::fake()` call.
     * 2. The root is emptied before every test, so leftovers of an earlier run
     *    — or of a crashed run — cannot influence the next one. That is what
     *    makes a repeated full run in the same checkout reproducible.
     */
    protected function rootEveryDiskInTheTestTree(): void
    {
        foreach (self::FAKE_DISKS as $disk) {
            Storage::fake($disk);

            $this->fakeDiskRoots[$disk] = rtrim(Storage::disk($disk)->path(''), DIRECTORY_SEPARATOR);
        }
    }

    /**
     * Empty every faked disk root, so a test run leaves no residue behind.
     *
     * Called from `tearDown()` BEFORE `parent::tearDown()` on purpose: the
     * parent flushes the application, and this runs on the plain filesystem
     * rather than through the `Storage` facade because a test may have
     * replaced that facade with a Mockery mock.
     *
     * Only the test tree is emptied. `storage/app/private` and
     * `storage/app/media` are deliberately left untouched — in a local
     * checkout they hold real dev media, and no test may delete that.
     */
    protected function purgeFakeDiskRoots(): void
    {
        $filesystem = new Filesystem;

        foreach ($this->fakeDiskRoots as $root) {
            $filesystem->cleanDirectory($root);
        }
    }

    /**
     * The root directory of every faked disk, keyed by disk name.
     *
     * @return array<string, string>
     */
    protected function fakeDiskRoots(): array
    {
        return $this->fakeDiskRoots;
    }

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
