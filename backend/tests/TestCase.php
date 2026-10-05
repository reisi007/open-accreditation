<?php

namespace Tests;

use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use ReflectionProperty;
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

    /**
     * The token `Storage::fake()` appends to the root it installs, resolved
     * once per OS process.
     *
     * `Storage::fake()` builds its root like this (Laravel 13,
     * `Illuminate\Support\Facades\Storage::fake()`, lines 108-112):
     *
     *     $root = self::getRootPath($disk);          // storage/framework/testing/disks/<disk>
     *     if ($token = ParallelTesting::token()) {
     *         $root = "{$root}_test_{$token}";
     *     }
     *
     * and `ParallelTesting::token()` (line 297-302) falls back to
     * `$_SERVER['TEST_TOKEN'] ?? false` — a variable only *paratest* sets, for
     * each of its workers. Two consequences, and they are the whole bug:
     *
     *  - Under `--parallel` the token is set, so workers of ONE run already
     *    get separate roots (`…/media_test_1`, `…/media_test_2`, …). This part
     *    was never broken.
     *  - Under a PLAIN `php artisan test` it is `false`, the suffix is skipped
     *    and every process roots its faked disks at the very same
     *    `storage/framework/testing/disks/<disk>`. `fake()` cleans that
     *    directory on every single call, so two concurrent runs in one checkout
     *    delete each other's files mid-test — measured on this suite: 383 and 5
     *    spurious failures, `Unable to find a file or directory at path [...]`.
     *
     * So the token is the one lever that makes a fake root process-private, and
     * it has to be pulled here rather than inside `rootEveryDiskInTheTestTree()`
     * because ~25 test classes call `Storage::fake()` themselves and re-derive
     * the very same shared root.
     */
    private static ?string $fakeDiskToken = null;

    /**
     * Whether this process already armed its shutdown cleanup — see
     * `armProcessRootCleanup()`.
     */
    private static bool $processRootCleanupArmed = false;

    /**
     * Whether this process already pruned the pre-tokenization roots — see
     * `pruneLegacySharedRoots()`.
     */
    private static bool $legacyRootsPruned = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Must precede the first `Storage::fake()` call of ANY kind, this one
        // in `rootEveryDiskInTheTestTree()` as much as the ones inside
        // individual test classes.
        self::isolateFakeDisksInThisProcess();

        $this->rootEveryDiskInTheTestTree();

        $this->speakGermanByDefault();
    }

    /**
     * The suite's baseline client is a GERMAN-speaking one.
     *
     * ## Why this is here and not left to chance
     *
     * `SymfonyRequest::create()` — which `MakesHttpRequests::call()` builds every
     * test request with — INJECTS a default
     * `HTTP_ACCEPT_LANGUAGE: en-us,en;q=0.5` (measured:
     * `vendor/symfony/http-foundation/Request.php`, the `array_replace` default
     * block). Since `SetRequestLocale` negotiates from that header, every test
     * in this suite would otherwise be an **English** client — and every
     * existing German `{message}` assertion (`MailTest`, `QueuedMailTest`,
     * `AdminSubApplicationResendTest`) would go red on a tree where the German
     * catalogs are perfectly correct.
     *
     * Those assertions are contracts, not decoration, so the baseline is fixed
     * HERE, once, instead of being patched into three test classes: it makes the
     * whole suite speak the product's source language, which is also what the
     * SPA does at boot (`I18nProvider.tsx` activates `de`).
     *
     * `withHeader('Accept-Language', …)` still overrides this per test — that is
     * how `ServerMessageLocaleTest` reaches the `en` catalog.
     */
    private function speakGermanByDefault(): void
    {
        $this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => 'de']);
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

        // Hand the STRICT-MODE switch back to the environment. A test that
        // called `forceStrictAuthState()` to prove the wiring must not decide
        // for the tests that run after it in the same process — the switch is
        // process-global, and leaking it would make one test's override another
        // test's environment.
        self::forceStrictAuthState(null);

        parent::tearDown();
    }

    /**
     * Make every faked disk of THIS process process-private.
     *
     * Resolving `ParallelTesting`'s token to a per-process value is what makes
     * `Storage::fake()` install a root no other process can see, and therefore
     * what makes two concurrent full runs in one checkout independent. It is the
     * only hook that covers *all* faked disks: a test class calling
     * `Storage::fake('media')` itself re-runs the very same root computation, so
     * faking only from `rootEveryDiskInTheTestTree()` would leave those roots
     * shared again.
     *
     * Setting a token outside a paratest run is otherwise inert, because every
     * other framework reader of it is gated on `ParallelTesting::inParallel()`,
     * which additionally requires `LARAVEL_PARALLEL_TESTING` (only paratest sets
     * that): the test-database name (`TestDatabases::testDatabase()`), the
     * compiled-view path (`TestViews`) and the cache prefix (`TestCaches`) are
     * all reached exclusively from `setUpProcess()`/`setUpTestCase()`/
     * `setUpTestDatabase()` callbacks, and those are wrapped in
     * `whenRunningInParallel()`. `Storage::fake()` (line 110) is the one place
     * that reads the token unconditionally. Nothing outside `tests/` is
     * touched, so production cannot see any of this.
     *
     * Public and static so the cross-process probe
     * (`tests/Support/FakeDiskSuiteProbeTest.php`) can install the exact same
     * isolation in a child process instead of a copy of it.
     */
    public static function isolateFakeDisksInThisProcess(): string
    {
        $token = self::$fakeDiskToken ??= self::resolveFakeDiskToken();

        ParallelTesting::resolveTokenUsing(static fn (): string => $token);

        self::pruneLegacySharedRoots();
        self::armProcessRootCleanup($token);

        return $token;
    }

    /**
     * Delete the UNTOKENED roots that predate this arrangement, once per process.
     *
     * Before the token, `Storage::fake()` rooted every disk at
     * `…/disks/<disk>` and nothing ever removed those directories, so a
     * developer's checkout can still carry `…/disks/media` full of files from
     * runs of the old code. Nothing reads them any more — every root is
     * tokenized now — but leaving them would mean `storage/framework/testing/`
     * never returns to just its tracked `.gitignore`, which is precisely the
     * guarantee WP-11 established and which the per-process suffix would
     * otherwise appear to break.
     *
     * Only the four roots this suite itself fakes are removed, and only
     * untokened ones (`<disk>` exactly — never `<disk>_test_<token>`, which
     * belongs to whichever process owns it). `s3` and anything else outside
     * `FAKE_DISKS` is not touched, and `storage/app/**` is nowhere near this.
     */
    private static function pruneLegacySharedRoots(): void
    {
        if (self::$legacyRootsPruned) {
            return;
        }

        self::$legacyRootsPruned = true;

        self::pruneLegacySharedRootsIn(storage_path('framework/testing/disks'));
    }

    /**
     * The sweep behind `pruneLegacySharedRoots()`, with the tree passed in.
     *
     * Split out so it can be pointed at a throwaway directory by
     * `FakeDiskProcessIsolationTest`: the version that matters operates on the
     * real `storage/framework/testing/disks` and DELETES, so testing it there
     * would mean a test destroying the tree another test is asserting on. The
     * parameter changes nothing about the logic — same `FAKE_DISKS` list, same
     * exact-name match that excludes every `_test_<token>` root.
     *
     * @return list<string> The names of the removed directories.
     */
    public static function pruneLegacySharedRootsIn(string $testingTree): array
    {
        $filesystem = new Filesystem;
        $removed = [];

        foreach (self::FAKE_DISKS as $disk) {
            $legacy = $testingTree.DIRECTORY_SEPARATOR.$disk;

            // Comparing the whole name against the untokened one is what
            // excludes `<disk>_test_<token>`: a name that merely *contains* the
            // disk name belongs to a process that may be running right now, and
            // deleting that is the very bug this arrangement removes.
            if (basename($legacy) === $disk && is_dir($legacy)) {
                $filesystem->deleteDirectory($legacy);

                $removed[] = $disk;
            }
        }

        return $removed;
    }

    /**
     * The token identifying this OS process, computed exactly once.
     *
     * Unique per process because the OS PID is: two runs in the same checkout
     * are two live processes and cannot share one, which is the whole property
     * the concurrent-runs fix rests on. The `paratest` token is kept as a prefix
     * when there is one, so a worker's path stays recognisable
     * (`media_test_3-4242`) while still being unique — paratest restarts its
     * worker numbering at `1` per run, so on its own it isolates workers of ONE
     * run but not two concurrent runs.
     *
     * Statically cached, so every test of a process sees the same root. That is
     * not a nicety: a test that writes a file and a later assertion in the same
     * test that reads it back, or `Tests\TestCase::purgeFakeDiskRoots()` in
     * `tearDown()` cleaning the root the test body is still using, both break
     * the moment the root moves between calls.
     *
     * A PID is unique among *live* processes, which is exactly the property
     * needed. It is not unique forever: a root orphaned by a process that was
     * `SIGKILL`ed (so its shutdown hook never ran) would be inherited by whatever
     * process gets that PID next. Harmless — `Storage::fake()` empties the root
     * it installs, and the new owner's shutdown hook then removes the directory —
     * but it is the reason this is a PID and not, say, a `uniqid()`: a random
     * token would leave a fresh orphan directory behind on every hard kill, with
     * nothing guaranteed to reclaim it.
     */
    private static function resolveFakeDiskToken(): string
    {
        $paratestToken = $_SERVER['TEST_TOKEN'] ?? null;

        // `Storage::fake()` appends the suffix only `if ($token = …)`, i.e. only
        // for a token PHP considers truthy — and `'0'` is falsy. Checking the
        // same way keeps this in step with the framework: a `TEST_TOKEN` of `'0'`
        // or `''` is no worker identity and must not turn into a
        // `media_test_-45123` root.
        return is_string($paratestToken) && $paratestToken !== '' && $paratestToken !== '0'
            ? $paratestToken.'-'.getmypid()
            : (string) getmypid();
    }

    /**
     * The token of the current process, for assertions and diagnostics.
     */
    public static function fakeDiskToken(): string
    {
        return self::$fakeDiskToken ?? self::resolveFakeDiskToken();
    }

    /**
     * Delete this process' own fake roots once, when the process ends.
     *
     * `purgeFakeDiskRoots()` empties the roots after every single test, but the
     * *directories* are what `Storage::fake()` creates and what the per-process
     * suffix now makes unique — so a run would leave four `…_test_<pid>`
     * directories behind and `storage/framework/testing/` would no longer hold
     * just its tracked `.gitignore`. A shutdown hook removes exactly this
     * process' roots, which is the one moment no test can be looking at them.
     *
     * A shutdown function rather than a `tearDown()`: it also runs after a test
     * failure and after a PHP fatal error, which is when a half-run is most
     * likely to leave something behind.
     *
     * It removes ONLY directories carrying this process' token, so a suite
     * running concurrently in the same checkout is never affected.
     */
    private static function armProcessRootCleanup(string $token): void
    {
        if (self::$processRootCleanupArmed) {
            return;
        }

        self::$processRootCleanupArmed = true;

        // Resolved now, while the container is alive: at shutdown the
        // application may already be torn down and `storage_path()` unavailable.
        $testingTree = storage_path('framework/testing/disks');

        register_shutdown_function(static function () use ($testingTree, $token): void {
            self::purgeFakeDiskRootsOwnedBy($testingTree, $token);
        });
    }

    /**
     * Delete every root below `$testingTree` that carries `$token`, and only
     * those.
     *
     * The token is per process, so "carries the token" is exactly "was created
     * by this process" — no liveness check and no heuristic is needed, which is
     * what keeps this safe to run while other suites are active.
     *
     * Deliberately NOT sweeping roots of *other* tokens: they may belong to a
     * process that is running right now, and deleting those is precisely the bug
     * this whole arrangement exists to remove. A root orphaned by a process that
     * was `SIGKILL`ed and never got to run its shutdown hook therefore stays
     * until its PID comes up again — at which point the next run reusing that
     * PID cleans and finally deletes it. That trade is deliberate: a reaper
     * guessing liveness from PIDs could delete a live suite's files.
     *
     * For the same reason this leaves the shared `…/disks/` directory itself in
     * place and only ever removes what is inside it. Removing the parent as well
     * would mean deleting a directory another process may be creating files in
     * right now, for no gain: it is gitignored, and an empty one cannot affect
     * any run.
     *
     * @return list<string> The names of the removed directories.
     */
    public static function purgeFakeDiskRootsOwnedBy(string $testingTree, string $token): array
    {
        if ($token === '' || ! is_dir($testingTree)) {
            return [];
        }

        $filesystem = new Filesystem;
        $suffix = '_test_'.$token;
        $removed = [];

        foreach ($filesystem->directories($testingTree) as $directory) {
            if (str_ends_with($directory, $suffix)) {
                $filesystem->deleteDirectory($directory);

                $removed[] = basename($directory);
            }
        }

        return $removed;
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
     *
     * Empties, never deletes: the roots this process owns are removed as a
     * whole by the shutdown hook (`armProcessRootCleanup()`), while a test that
     * is still running must keep its root addressable.
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
     * The URL scheme a public link must carry, derived from `config('app.url')`.
     *
     * The contract is the one `VerifyLink`/`BadgeRenderService`/`AuthController`
     * all document: *the scheme always follows `config('app.url')`* — https in
     * prod, http in local. A local install behind plain http must not be handed
     * `https://` links (nothing terminates TLS there, so the link in a pass
     * mail or a PKPASS barcode would be dead on arrival), and an install behind
     * TLS must not be handed `http://` links. Hardcoding either scheme into an
     * assertion therefore asserts the *environment*, not the code — MEASURED:
     * with `APP_URL=http://accreditation.test` exactly 10 tests of this suite
     * went red on `'https://…/verify/'` vs `'http://…/verify/'` and nothing
     * about the product had changed.
     *
     * The `?: 'https'` mirrors `VerifyLink:25` exactly, so a helper and the
     * code under test can never disagree about the degenerate case (an
     * `APP_URL` without a scheme).
     */
    protected function appUrlScheme(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
    }

    /**
     * The verify URL a public link is expected to carry, as a prefix.
     *
     * Split deliberately into the two parts the code actually decides, so a test
     * using it still pins the interesting half — the HOST comes from the
     * mandant's own domain (`verband-a.test`), never from `config('app.url')` —
     * while the scheme follows the configuration (see `appUrlScheme()`).
     *
     * @param  string  $host  the mandant domain hostname the link must point at
     */
    protected function expectedVerifyUrlPrefix(string $host): string
    {
        return $this->appUrlScheme().'://'.$host.'/verify/';
    }

    /**
     * `expectedVerifyUrlPrefix()` plus the signed token — the complete link.
     */
    protected function expectedVerifyUrl(string $host, string $token): string
    {
        return $this->expectedVerifyUrlPrefix($host).$token;
    }

    /**
     * Log a user in via the JWT guard and put the token on the wire the way
     * production does — as the httpOnly cookie.
     *
     * The in-memory token and the guard's memoised user are dropped
     * afterwards, so the next request is genuinely validated from the cookie
     * rather than answered out of process-global state. `login()` had to run
     * first because it is the only way to MINT a token; that is a fixture
     * step, not the authentication under test.
     */
    protected function actingAsApi(User $user): static
    {
        $token = auth('api')->login($user);

        $this->withJwtCookie($token);

        $this->forgetJwtAuthState();

        return $this;
    }

    /**
     * A cookie-carrying request for a loop over HTTP verbs (`DataProvider`).
     *
     * The trap this replaces: `MakesHttpRequests::call()` takes `$cookies` as
     * its THIRD PARAMETER and defaults it to `[]` — the verb helpers are the
     * ones that fill it in. Each of the thirteen `get()`/`post()`/`put()`/…
     * helpers in the trait starts with
     * `$cookies = $this->prepareCookiesForRequest();` and passes it on; a
     * direct `->call($method, $uri)` passes nothing, so **no** cookie is
     * transported no matter what `withCookie()`/`withJwtCookie()` configured.
     *
     * MEASURED: `actingAsApi($mandantAdmin)->call('get', '/api/admin/mandants')`
     * answered 401 ("Unauthenticated.") while the identical request through
     * `getJson()` answered 403. Both claims cannot hold; the 401 is the truth,
     * because the cookie never left the test. A test asserting 403 through
     * `call()` was only ever green because the `JWT::$token` singleton that
     * `login()` left behind answered it — the 403 was the harness, not the
     * authorisation check.
     *
     * The JSON verbs are used rather than their plain counterparts so the
     * cookie goes through `prepareCookiesForJsonRequest()` exactly as every
     * other test in this suite does; mixing both would make the next reader
     * wonder which one carries the cookie. `shouldRenderJsonWhen()` covers
     * `api/*` regardless, so a JSON verb changes no status code.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function callAsApi(string $method, string $uri, array $parameters = []): TestResponse
    {
        return match (strtolower($method)) {
            'get' => $this->getJson($uri, $parameters),
            'post' => $this->postJson($uri, $parameters),
            'put' => $this->putJson($uri, $parameters),
            'patch' => $this->patchJson($uri, $parameters),
            'delete' => $this->deleteJson($uri, $parameters),
            default => throw new InvalidArgumentException(
                "callAsApi(): '{$method}' is not one of get/post/put/patch/delete."
            ),
        };
    }

    /**
     * THE ONLY CHANNEL for putting a JWT on the wire for a `/api/*` request.
     *
     * ## Why this is a helper and not a call the tests make themselves
     *
     * Every obvious spelling is broken, and none of them says so:
     *
     *  - `withCookie($name, $value)` alone transports NOTHING on a JSON
     *    request. `MakesHttpRequests::json()` hands
     *    `prepareCookiesForJsonRequest()` to the kernel, and that returns
     *    `$this->withCredentials ? $this->prepareCookiesForRequest() : []` —
     *    so without `withCredentials()` the cookie is dropped silently.
     *    MEASURED: `/api/auth/me` answers 401.
     *  - `withCookie()` + `withCredentials()` is worse: it transports a
     *    CIPHERTEXT. `prepareCookiesForRequest()` runs every `defaultCookies`
     *    entry through `encrypt(...)` prefixed with `CookieValuePrefix`, and
     *    nothing on the `/api/*` path decrypts it — `EncryptCookies` is a
     *    `web`-group middleware and `bootstrap/app.php` only configures the
     *    host allow-list, so the `api` group never gets it. jwt-auth runs with
     *    `decrypt_cookies => false` and therefore reads the ciphertext.
     *    MEASURED: 401, and the value arriving at the route begins `eyJpdiI6`
     *    (`{"iv":`) where the raw token begins `eyJ0eXAi` (`{"typ":`).
     *  - `withUnencryptedCookie()` alone is ALSO dropped — the
     *    `withCredentials` switch is what turns cookie transport on at all.
     *    MEASURED: 401.
     *
     * So the working channel needs BOTH, and the pair is easy to get wrong in
     * a way that still returns 200 — the 200 then comes from the
     * `JWT::$token` singleton that `login()` left behind, not from the
     * request. That is the false positive this helper exists to make
     * impossible to write: `withCredentials()` appears exactly once, here,
     * paired with `withUnencryptedCookie()`, and
     * `tests/Feature/ForbiddenJwtCookieChannelTest` fails if a `withCookie(`
     * or `withCredentials(` call reappears anywhere in `tests/`.
     *
     * The plain token value is correct, not a workaround: production's cookie
     * is plain too, because `EncryptCookies` never runs on this route group.
     *
     * @param  string|null  $token  `null` re-uses whatever was put on the wire
     *                              before; a value replaces it.
     */
    protected function withJwtCookie(?string $token = null): static
    {
        if ($token !== null) {
            $this->withUnencryptedCookie(config('jwt.cookie_key_name'), $token);
        }

        $this->withCredentials();

        return $this;
    }

    /**
     * Drop every piece of process-global auth state that could answer a
     * request without looking at the token.
     *
     * Two, and they are independent:
     *
     *  - `JWT::$token` — a singleton on `tymon.jwt`. `auth('api')->login()` and
     *    `JWTAuth::make()` leave the token there, and the parser falls back to
     *    it when the request carries no usable cookie. A 401 measured after a
     *    logout can therefore come from "there is no token at all" instead of
     *    from the blacklist, which is precisely how the earlier
     *    "logout revokes the token" reading was a false positive.
     *  - `JWTGuard::$user` — the guard memoises its resolved user and the
     *    guard is a container singleton that SURVIVES between `getJson()` calls
     *    inside one test. A second protected request can be answered from that
     *    memo without parsing anything. `AuthManager::forgetGuards()` drops the
     *    resolved instances while keeping the manager (dropping the manager
     *    itself would lose the registered `jwt` driver extension).
     */
    protected function forgetJwtAuthState(): void
    {
        app('tymon.jwt')->unsetToken();

        app('auth')->forgetGuards();
    }

    /**
     * Whether the process-global `JWT::$token` currently holds a token, read
     * by reflection so asserting on it cannot disturb it.
     */
    protected function inMemoryJwtTokenIsSet(): bool
    {
        return (new ReflectionProperty(app('tymon.jwt'), 'token'))->getValue(app('tymon.jwt')) !== null;
    }

    /* ------------------------------------------------------------------ */
    /* STRICT MODE: may a request in this suite be answered out of memory? */
    /* ------------------------------------------------------------------ */

    /**
     * The environment variable that turns STRICT MODE on.
     *
     * Not a `phpunit.xml` default and deliberately not one: an `<env>` entry
     * there would make every ordinary run pay for the strictness measurement,
     * and it would also make it impossible to run the *relaxed* suite for a
     * comparison. The switch has to be something a person types.
     */
    public const STRICT_AUTH_STATE_ENV = 'JWT_AUTH_STATE_STRICT';

    /**
     * The resolved value of the switch, or null while it is undecided.
     *
     * `null` means "ask the environment again", which is what lets a test flip
     * the switch and still see the ambient default afterwards.
     */
    private static ?bool $strictAuthState = null;

    /**
     * STRICT MODE: clear every piece of process-global auth state before each
     * request, so an authenticated answer can only come from the request.
     *
     * ## What it is for
     *
     * `auth('api')->login()` leaves a token in the `JWT::$token` singleton, and
     * the parser falls back to it when the request carries no usable cookie. A
     * test whose cookie silently stopped being transported is therefore still
     * **green** — it just answers out of memory. That failure is invisible to
     * every other mechanism: the status code is right, the assertion passes,
     * and the suite reports success.
     *
     * The only way to see the whole of it at once is to take the memory away:
     * with this on, the wire is the ONLY source of authentication, and every
     * test that was leaning on the singleton goes red. It turns a code read
     * into a whole-suite measurement, and it is how the one remaining hole in
     * the channel migration was found (`SameOriginGuardTest`, which called
     * `withUnencryptedCookie()` and asserted a 403 that the singleton produced).
     *
     * ## What it costs, MEASURED on this suite
     *
     * **MEASURED on this tree, 2026-09-30: ZERO red tests** — the strict run and
     * the relaxed run report the same (empty) failure set, 1732 passed / 0
     * failed / 1 skipped in both. (The count is a property of the TREE, not of
     * the mode, and it moves whenever tests are added — 1682 was the figure a
     * few commits earlier. The claim that matters is the empty failure set and
     * its identity across the two modes; the number is quoted with its date for
     * the same reason everything else here is.)
     *
     * The number is zero rather than one because of the premise probe, and the
     * direction of that matters: BEFORE its exemption
     * (`JwtCookieChannelTest::test_the_in_memory_token_alone_can_authenticate_a_request`)
     * the strict run was **exactly one red** — and that one was the probe
     * itself, which is *supposed* to be the exception. Its whole job is to show
     * that the singleton CAN answer a request, and that is what makes every
     * other "the 401 above is trustworthy" claim in the suite mean something.
     * It opts out through `$answersRequestsFromTheInMemoryJwtToken`, and
     * `JwtAuthStateStrictnessTest` pins that exactly one class does. So the one
     * red test the mode would otherwise have is the one test that is allowed to
     * use the singleton, on purpose, and it stays green.
     *
     * **That number is a measurement, not a gate, and nothing keeps it true.**
     * Read as a gate it would be a trap in both directions. A *larger* number is
     * a real regression — some test started answering out of memory — but only
     * the strict run itself can see it, and only if somebody types the switch.
     * A *smaller* number than zero is impossible, which is the reassuring half;
     * the unnerving half is that the number reaching zero is ALSO what a suite
     * in which the exemption quietly stopped mattering would report, and nothing
     * inside a run can tell those two apart. So the number is reported with its
     * mode and its date, never as a threshold.
     *
     * What IS enforced by a mechanism is the narrower fact underneath:
     * `JwtAuthStateStrictnessTest::test_exactly_one_class_is_exempt_from_the_strict_clearing`
     * pins the EXEMPT SET to exactly one class — and it fails in BOTH
     * directions, because "two" would mean the mode no longer covers the suite
     * and "none" would mean the premise probe had been deleted. The red count is
     * not pinned by anything, deliberately: only a whole run under the switch
     * produces it. Re-measuring it is `JWT_AUTH_STATE_STRICT=1 php artisan test`,
     * and any number other than the one above is a finding to READ, not a gate
     * to satisfy.
     *
     * The migration it audits is therefore complete **as of the measurement
     * above**: no test outside the exempt class is answering out of memory, and
     * no test needs a two-sentence excuse.
     *
     * It stays opt-in for two reasons. First, the honest number above is a
     * property of THIS tree, and a future test that legitimately needs the
     * singleton would otherwise turn the default run red for everybody; the
     * switch lets the suite say "not today" without deleting the measurement.
     * Second, the default run is the one CI runs on every push, and it must
     * measure the product, not the harness.
     *
     * ## How to run it
     *
     *     JWT_AUTH_STATE_STRICT=1 php artisan test
     *
     * Cost per request when it is OFF: one static read and one branch. That is
     * the whole price of the default run, and the full suite measured within
     * noise of its previous duration.
     */
    public static function strictAuthStateIsEnabled(): bool
    {
        return self::$strictAuthState ??= self::resolveStrictAuthState();
    }

    /**
     * Force the switch for the rest of this process, or hand it back to the
     * environment with `null`.
     *
     * Public because a test has to be able to prove the wiring, and because the
     * strict suite itself is easier to trust when the value is a number
     * somebody wrote down rather than an ambient surprise.
     */
    public static function forceStrictAuthState(?bool $enabled): void
    {
        self::$strictAuthState = $enabled;
    }

    /**
     * Read the switch from the environment, treating the usual spellings of
     * "on" as on and everything else as off.
     *
     * Three sources, in precedence order, and the order is the contract: a
     * value the harness itself placed (PHPUnit config) must win over whatever
     * the ambient shell happens to carry, or a run on a developer's machine
     * would silently change the mode the suite is measuring in.
     *
     *  - `$_SERVER` — the CLI prefix, which is the documented way to run this:
     *    `JWT_AUTH_STATE_STRICT=1 php artisan test`. MEASURED (php -r, SAPI
     *    `cli`, `variables_order=GPCS`): the prefix lands here and in
     *    `getenv()`.
     *  - `$_ENV` — a PHPUnit `<env>` entry. `PhpHandler::handleEnvVariables()`
     *    writes this superglobal explicitly
     *    (`vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php:166-167`).
     *  - `getenv()` — the same values as the two above plus anything a test
     *    set with `putenv()` and neither superglobal saw.
     *
     * Two facts about this order that used to be wrong in the comment above it,
     * both measured or cited rather than assumed:
     *
     *  - `$_ENV` is **not** populated by the environment at all on a default
     *    CLI. `variables_order` here is `GPCS` (no `E`), so PHP never copies an
     *    env var into it — the `VAR=1` prefix is invisible to `$_ENV` and only
     *    `$_SERVER` catches it. Reading `$_ENV` first would be reading a
     *    guaranteed-empty array in the documented invocation.
     *  - PHPUnit's `<env>` entries do **not** reach `$_SERVER`: only
     *    `<server>` entries do (`PhpHandler::handleServerVariables()`,
     *    `PhpHandler.php:125-130`). This is why the `getenv()` call below is
     *    load-bearing rather than a third spelling of the first two — it is the
     *    source that sees an `<env>` entry when the superglobals do not.
     */
    private static function resolveStrictAuthState(): bool
    {
        $raw = $_SERVER[self::STRICT_AUTH_STATE_ENV]
            ?? $_ENV[self::STRICT_AUTH_STATE_ENV]
            ?? getenv(self::STRICT_AUTH_STATE_ENV)
            ?? false;

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * Whether a test in this class is allowed to answer its requests from the
     * in-memory JWT token even in STRICT MODE.
     *
     * The default is `false`, and that is the whole point: a test class has to
     * say out loud that it needs the singleton. Exactly one class does
     * (`JwtCookieChannelTest`, the premise probe), and
     * `JwtAuthStateStrictnessTest` fails if that ever becomes two or zero —
     * "nobody needs it" would mean the measurement itself had been quietly
     * dropped, which is the same class of failure as a guard nobody reads.
     */
    protected static bool $answersRequestsFromTheInMemoryJwtToken = false;

    /**
     * Clear the in-memory auth state before every request, in STRICT MODE only.
     *
     * Overriding `call()` rather than the thirteen verb helpers is deliberate
     * and it is what makes the measurement COMPLETE: every request in the
     * suite funnels through `call()` — `getJson()`, `postJson()`,
     * `json()`, `withHeaders()->post()` and the raw entry point alike — so one
     * override here covers all of them. Overriding the verbs would leave a
     * hole exactly where `json()` and `call()` are used.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (self::strictAuthStateIsEnabled() && ! static::$answersRequestsFromTheInMemoryJwtToken) {
            $this->forgetJwtAuthState();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
