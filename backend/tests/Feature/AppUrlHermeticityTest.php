<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Mandant;
use App\Models\User;
use App\Services\QrTokenService;
use App\Support\MandantContext;
use App\Support\VerifyLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE REGRESSION GUARD for the suite's dependency on the developer's `.env`.
 *
 * ## What broke, measured
 *
 * `php artisan test` does not read a configuration of its own: it reads
 * `backend/.env`, the file the developer's dev stack also needs. `APP_URL` in
 * that file decided the fate of 1652 tests, in two independent ways. Full suite,
 * only `.env` varied, nothing in the repo changed:
 *
 * | `APP_URL`                    | failed | passed |
 * |------------------------------|--------|--------|
 * | `https://accreditation.test` |      0 |   1651 |
 * | `http://accreditation.test`  |     10 |   1641 |
 * | `https://localhost:5173`     |    515 |   1137 |
 * | `http://localhost:5173`      |    520 |   1131 |
 * | *(empty)*                    |    519 |   1132 |
 *
 * ## The 515 are the HOST, and the mechanism is not the obvious one
 *
 * The scheme (`https:` vs `http:`) is the small half — 10 tests, and the
 * documented contract says the tests were the party that was wrong (see
 * `TestCase::appUrlScheme()`).
 *
 * The host is the large half, and it has nothing to do with links.
 * `MakesHttpRequests` prefixes every test URI with `config('app.url')`, so
 * `APP_URL` decides the `Host` header of EVERY request the suite makes. A
 * **loopback** host then hits the dev-server fallback in
 * `MandantContextMiddleware::handle()`:
 *
 *     if ($this->isLoopback($host) && app()->environment('local', 'testing')) {
 *         MandantContext::set(MandantContext::default());
 *
 * That branch exists for `php artisan serve` on 127.0.0.1 and is correct there.
 * In a test it is destructive: every test installs its own tenant in `setUp()`
 * with `MandantContext::set($this->mandantA)`, `default()` returns `null` (the
 * factory sets `is_primary => false`, so no primary row exists), and
 * `MandantContext::set(null)` runs `app()->offsetUnset()` — which erases
 * exactly the mandant the test had installed. From then on the mandant-scoped
 * queries find nothing (404) and every gate resolves its role against
 * `currentId() === null` and denies (403).
 *
 * MEASURED on one test: `currentId()` is `1` before the request and `null`
 * after, with `APP_URL=http://localhost:5173`; with
 * `APP_URL=https://accreditation.test` it is still `1` afterwards. An empty
 * `APP_URL` hits the same branch, because `url('/api/x')` then falls back to
 * `http://localhost`.
 *
 * ## Why the fix is a pin and not a code change
 *
 * The tempting repair is to stop `MandantContextMiddleware` from overwriting a
 * mandant in `testing`. That would delete the dev-server behaviour this
 * repository depends on, and it would leave the suite still *reading the
 * developer's `.env`* for the host of every request. The suite is a separate
 * environment and belongs in `phpunit.xml` next to the 16 values already pinned
 * there (`APP_KEY`, `JWT_SECRET`, `DB_*`, `MAIL_*`, `CACHE_STORE`, …).
 *
 * ## What each test below holds in place
 *
 * 1. the pin exists in `phpunit.xml` and is `force="true"` — a bare pin is
 *    still ambient, because a process value beats a non-forced `<env>` pin, and
 *    `APP_URL=http://localhost:5173 php artisan test` is exactly what somebody
 *    types while debugging the dev stack;
 * 2. the resolved host is not loopback — the property that actually matters, so
 *    it stays true if the pinned value is ever changed to another plain host;
 * 3. the behaviour the 519/520 broke: a mandant a test installs survives an
 *    in-suite request;
 * 4. the documented contract itself — the scheme follows `config('app.url')`
 *    in BOTH directions, so nobody may "fix" the product code to always emit
 *    `https` and silently break real local installs.
 */
class AppUrlHermeticityTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | 1 — the pin exists, and it is forced
     | ------------------------------------------------------------------- */

    /**
     * Read from the FILE, not from `config()`.
     *
     * `config('app.url')` inside this suite can only ever report the pin, so a
     * config assertion cannot tell "the pin is in force" from "the pin is gone
     * and the developer's `.env` happens to hold the same value". Reading the
     * committed file answers the question that matters, independently of
     * whatever `.env` the checkout carries. The same reasoning, and the same
     * idiom, as `SchemaHardeningTest::test_the_suite_pin_is_an_explicit_
     * isolation_choice()`.
     */
    public function test_the_suite_pins_app_url_and_the_pin_is_forced(): void
    {
        $phpunit = File::get(base_path('phpunit.xml'));

        $this->assertStringContainsString(
            '<env name="APP_URL" value="https://accreditation.test" force="true"/>',
            $phpunit,
        );
    }

    /* ---------------------------------------------------------------------
     | 2 — the resolved host must not be loopback
     | ------------------------------------------------------------------- */

    /**
     * The property, not the literal.
     *
     * The mandant-wipe fires on `localhost`, `127.0.0.1`, `::1` and every
     * `*.localhost` subdomain — the exact host set
     * `MandantContextMiddleware::isLoopback()` treats as loopback. Pinning any
     * other host is equally correct, so this test is written against the
     * property and would still hold after a deliberate change of the pinned
     * value; only a change *back* to a loopback host can break it.
     *
     * The second assertion is the provenance: the value in force is the pinned
     * one, not a leftover from the environment.
     */
    public function test_the_host_every_in_suite_request_carries_is_not_loopback(): void
    {
        $this->assertSame('https://accreditation.test', config('app.url'));

        $host = parse_url((string) url('/api/x'), PHP_URL_HOST);

        $this->assertIsString($host);
        $this->assertFalse(
            $this->isLoopback($host),
            'APP_URL resolves to the loopback host "'.$host.'", which re-activates the '
            .'MandantContextMiddleware dev fallback and erases the mandant each test installs.',
        );
    }

    /* ---------------------------------------------------------------------
     | 3 — the behaviour that broke: 519/520 tests
     | ------------------------------------------------------------------- */

    /**
     * The regression itself, asserted as behaviour rather than as configuration.
     *
     * Two assertions, and the order matters: the mandant identity is checked
     * after the request, so this fails on the *mechanism* (the middleware
     * overwrote the container binding) and not merely on a status code that some
     * other change could also produce.
     *
     * `GET /api/accreditations` is the public mandant-scoped list, so nothing but
     * the tenant resolution is under test here.
     */
    public function test_the_mandant_a_test_installed_survives_an_in_suite_request(): void
    {
        $this->mandant->categories()->create(['name' => 'Presse', 'slug' => 'presse']);

        $this->assertSame($this->mandant->id, MandantContext::currentId());

        $this->getJson('/api/accreditations')->assertOk();

        $this->assertSame(
            $this->mandant->id,
            MandantContext::currentId(),
            'the request replaced the mandant this test installed — see the class docblock.',
        );
    }

    /* ---------------------------------------------------------------------
     | 4 — the documented contract, in both directions
     | ------------------------------------------------------------------- */

    /**
     * `VerifyLink` documents: *"The scheme always follows `config('app.url')`
     * (https in prod, http in local)"* — and `AuthController::activationUrl()`
     * and `BadgeRenderService` document the same thing.
     *
     * That is the correct product behaviour, and it is the reason the ten tests
     * that hardcoded `https://…/verify/` were the party at fault, not the code: a
     * local install behind plain http must not be handed `https://` links, because
     * nothing terminates TLS there. So this test is what keeps the suite honest
     * in the other direction too — it fails if someone "repairs" the symptom by
     * making the product always emit `https`.
     *
     /**
     * `config([...])` rather than a second process: the value is what the test
     * is about, and setting it in-test is visible in the test itself.
     *
     * The port case earns its place: the link is built from the *mandant
     * domain*, so `app.url`'s port never reaches it, and a scheme parsed with
     * anything coarser than `parse_url()` would come out as `http` glued to the
     * rest of the URL.
     *
     * @return array<string, array{string}>
     */
    public static function appUrlProvider(): array
    {
        return [
            'https in production' => ['https://akademie.test'],
            'http behind a plain dev server' => ['http://akademie.test'],
            'http with a port' => ['http://akademie.test:8000'],
        ];
    }

    #[DataProvider('appUrlProvider')]
    public function test_the_verify_link_scheme_follows_the_configured_app_url(string $appUrl): void
    {
        config(['app.url' => $appUrl]);

        $application = $this->approvedApplication();
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);

        $token = app(QrTokenService::class)->make($application);
        $url = VerifyLink::for($application->fresh());

        $scheme = parse_url($appUrl, PHP_URL_SCHEME);

        $this->assertSame(
            $scheme.'://verband-a.test/verify/'.$token,
            $url,
            'the host must come from the mandant domain, the scheme from config("app.url"), '
            .'and the path must carry the signed token.',
        );
    }

    /**
     * The other half of the same contract: a mandant WITHOUT a domain falls back
     * to the `config('app.url')` host — and still to the `config('app.url')`
     * scheme. Without this the fallback path would be free to keep a hardcoded
     * `https` while the primary path follows the configuration.
     *
     * The scheme is asserted by starting from an `http` app URL: a fallback that
     * hardcoded `https` would produce a link a local install cannot open.
     */
    public function test_the_config_host_fallback_also_follows_the_configured_scheme(): void
    {
        config(['app.url' => 'http://akademie.test']);

        $url = VerifyLink::for($this->approvedApplication()->fresh());

        $this->assertStringStartsWith('http://akademie.test/verify/', $url);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * An approved application on a mandant-level accreditation, with the signed
     * QR token minted the way a dispatch site does.
     */
    private function approvedApplication(): Application
    {
        $category = $this->mandant->categories()->create(['name' => 'Presse', 'slug' => 'presse']);
        $accreditation = $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
        ]);

        $application = Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => User::factory()->create(['name' => 'Jane Doe'])->id,
            'status' => 'approved',
            'priority' => false,
        ]);

        app(QrTokenService::class)->make($application);

        return $application;
    }

    /**
     * The same loopback definition `MandantContextMiddleware::isLoopback()` uses.
     *
     * Duplicated on purpose rather than reflected into the middleware: the point
     * of this test is to pin the property against the *documented* host set, and
     * calling the production method would make the guard tautological — it would
     * follow any change of the very list it exists to catch.
     */
    private function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return str_ends_with($host, '.localhost');
        }

        $packed = inet_pton($host);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return $packed[0] === "\x7f";
        }

        return $packed === inet_pton('::1') || $packed === inet_pton('::ffff:127.0.0.1');
    }
}
