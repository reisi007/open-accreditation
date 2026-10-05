<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\SetRequestLocale;
use App\Jobs\SendMandantMail;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\FailedJob;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PlainTestMailable;
use Tests\TestCase;

/**
 * The admin mail surfaces answer in the REQUEST's language, not in German.
 *
 * ## The defect this pins
 *
 * All three `{message}` bodies were hardcoded German literals
 * (`AdminApplicationController`, `AdminSubApplicationController`,
 * `FailedMailController`), and the UI shows them verbatim on purpose
 * (`frontend/src/logic/serverActionMessage.ts`) — so an admin on the `en` locale
 * read a German success line. The strings moved to `lang/{de,en}/mails.php` and
 * `SetRequestLocale` negotiates the locale from `Accept-Language`.
 *
 * ## What a test of "it is localized" has to hold, or it is vacuous
 *
 *  - **Both locales, on every surface.** Three endpoints, not one: the gap was
 *    measured on the main, sub AND DLQ surfaces, and a fix applied to the first
 *    two would leave the third German. Each is exercised here.
 *  - **The DE body is unchanged.** Not "still German" in the loose sense, but
 *    BYTE-IDENTICAL to the string the E2E specs and `MailTest` already assert.
 *    A rewrite of the German wording would pass a "differs per locale" test
 *    while silently invalidating four existing contracts.
 *  - **The negotiation itself**, because "the catalogs exist" is not "the
 *    catalogs are reachable": an absent header, an unsatisfiable one, and the
 *    region forms (`en-US`) are all measured here.
 *  - **The claim survives translation.** `send()` only dispatches, so the
 *    wording may say QUEUED and never SENT. This is asserted per locale rather
 *    than trusted: a translator improving the EN catalog into "has been sent
 *    again" would restore the exact lie `serverActionMessage.ts` exists to
 *    delete, and no other test would notice.
 *
 * MUTATION, measured on 2026-10-05 over THIS class plus the four existing mail
 * classes (`MailTest`, `QueuedMailTest`, `AdminSubApplicationResendTest`,
 * `MailDeadLetterTest` — 105 tests before, 94 without the last):
 *
 *  - removing `SetRequestLocale` from the `api` group → **13 red of 94**: every
 *    `en` case, both 422 cases, the `Vary` case, the unsupported-language case,
 *    the `q=0` case, AND five of the pre-existing German assertions. That last
 *    group is the point: those German specs only pass because the middleware
 *    negotiated German for them, so the mutation shows the middleware is load-
 *    bearing for the whole suite and not just for this class.
 *  - rewriting `lang/en/mails.php`'s `queued` to "E-mail has been sent again." →
 *    **exactly 1 red**: `test_no_locale_claims_the_mail_was_sent`. Nothing else in
 *    the suite would notice a translator turning the queueing claim into a
 *    delivery claim, which is why that test exists.
 *  - inverting `SetRequestLocale::DEFAULT_LOCALE` to `en` → **2 red**: the
 *    no-header test and the `q=0` test (which resolves to the default). The
 *    German-by-header cases stay green, correctly — they name German explicitly.
 *  - deleting the `q=0` filter, i.e. deferring wholly to Symfony →
 *    **exactly 1 red**: `test_a_refused_language_is_dropped…`, the measured
 *    consequence of `Request::getPreferredLanguage()` ignoring the weight.
 */
class ServerMessageLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The German source wording, byte for byte.
     *
     * The SAME literal is asserted in `MailTest.php`, `QueuedMailTest.php`,
     * `AdminSubApplicationResendTest.php` and in three E2E specs. Those are the
     * contracts; this copy is not a second source of truth but the value those
     * contracts were measured against, so a drift shows up as a failure HERE
     * first, naming the catalog, rather than as three unrelated red specs.
     */
    private const DE_QUEUED = 'E-Mail wurde erneut in die Warteschlange gestellt.';

    private Mandant $mandant;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // The endpoints only dispatch (Position 45) and the suite runs `sync`, so
        // the fake is what keeps the delivery from running inline.
        Mail::fake();

        $this->seed(RoleSeeder::class);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Every surface, both locales
     | ------------------------------------------------------------------- */

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function surfaces(): iterable
    {
        yield 'main application resend' => ['main', '/api/admin/applications/%d/resend'];
        yield 'sub application resend' => ['sub', '/api/admin/sub-applications/%d/resend'];
        yield 'dead-letter requeue' => ['dlq', '/api/admin/failed-mails/%d/requeue'];
    }

    /**
     * The German answer, and it is asserted against the German HEADER rather
     * than against "no header".
     *
     * "No header at all" is not reachable through the test harness:
     * `SymfonyRequest::create()` injects `Accept-Language: en-us,en;q=0.5` into
     * every request it builds, so a request that does not name a language is a
     * request that names ENGLISH. `TestCase::speakGermanByDefault()` therefore
     * pins the suite's baseline to `de` — the same premise the existing German
     * specs (`MailTest`, `QueuedMailTest`, `AdminSubApplicationResendTest`)
     * depend on and the same locale the SPA boots with.
     *
     * The genuinely header-less client (curl without the flag, a mobile app)
     * is covered by `test_a_request_that_names_no_language_gets_german`, which
     * exercises the middleware directly — the only place where "absent" is
     * actually expressible.
     */
    #[DataProvider('surfaces')]
    public function test_the_queueing_message_is_german_for_a_german_client(string $kind, string $path): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson(sprintf($path, $this->rowId($kind)), [], ['Accept-Language' => 'de'])
            ->assertOk()
            ->assertJsonPath('message', self::DE_QUEUED);
    }

    /**
     * The DE default, on the middleware itself.
     *
     * This is the assertion that keeps every existing German spec valid, and it
     * is here rather than over HTTP because the harness cannot express an
     * absent header — see the sibling test's docblock. It pins the three inputs
     * that all mean "the client said nothing": null, empty, and whitespace.
     */
    public function test_a_request_that_names_no_language_gets_german(): void
    {
        $middleware = app(SetRequestLocale::class);

        foreach ([null, '', '   '] as $header) {
            $this->assertSame(
                SetRequestLocale::DEFAULT_LOCALE,
                $middleware->resolve($header),
                'an absent or blank Accept-Language must yield the default locale',
            );
        }

        $this->assertSame('de', SetRequestLocale::DEFAULT_LOCALE, 'German is this product\'s source language');
    }

    #[DataProvider('surfaces')]
    public function test_the_queueing_message_is_english_for_an_english_client(string $kind, string $path): void
    {
        $message = $this->actingAsApi($this->superAdmin())
            ->postJson(sprintf($path, $this->rowId($kind)), [], ['Accept-Language' => 'en'])
            ->assertOk()
            ->json('message');

        $this->assertIsString($message);
        $this->assertNotSame(self::DE_QUEUED, $message, 'an `en` client must not be answered in German');
        $this->assertSame(__('mails.queued', locale: 'en'), $message);
    }

    /**
     * The DE wording is not merely "still German" — it is the byte-identical
     * string the E2E specs and `MailTest` pin. A reworded German catalog would
     * pass every other test in this class and invalidate four existing
     * contracts, which is the failure mode this assertion exists to catch.
     */
    public function test_the_german_wording_is_unchanged_byte_for_byte(): void
    {
        $this->assertSame(self::DE_QUEUED, __('mails.queued', locale: 'de'));
    }

    /* ---------------------------------------------------------------------
     | Negotiation
     | ------------------------------------------------------------------- */

    /**
     * The browser's own header is what a real `en` admin sends, and the region
     * form is what it sends: `en-US,en;q=0.9`, not the bare `en` above. If the
     * region form failed, the fix would hold for curl and break for the browser
     * — the one combination nobody would notice in review.
     */
    public function test_a_regional_english_header_negotiates_english(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson(
                '/api/admin/applications/'.$this->approvedApplication()->id.'/resend',
                [],
                ['Accept-Language' => 'en-US,en;q=0.9'],
            )
            ->assertOk()
            ->assertJsonPath('message', __('mails.queued', locale: 'en'));
    }

    /**
     * A weighted header is ranked by quality, not by position.
     */
    public function test_the_language_quality_order_decides_not_the_header_order(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson(
                '/api/admin/applications/'.$this->approvedApplication()->id.'/resend',
                [],
                ['Accept-Language' => 'de;q=0.2,en;q=0.9'],
            )
            ->assertOk()
            ->assertJsonPath('message', __('mails.queued', locale: 'en'));
    }

    /**
     * A language we do not ship falls through to the DEFAULT, which is German —
     * the product's source language and the locale the SPA boots with.
     */
    public function test_an_unsupported_language_falls_back_to_german(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson(
                '/api/admin/applications/'.$this->approvedApplication()->id.'/resend',
                [],
                ['Accept-Language' => 'fr-FR,fr;q=0.9'],
            )
            ->assertOk()
            ->assertJsonPath('message', self::DE_QUEUED);
    }

    /**
     * `q=0` means "explicitly NOT acceptable", not "lowest preference" — and
     * what the filter does with such an item is DROP it from the candidate
     * list. It is not a veto over the outcome, which is exactly why this test
     * is named DROPPED and not "is not used": a list emptied by refusals falls
     * back to `DEFAULT_LOCALE` unconditionally, so a client that refuses
     * German is still answered in German.
     *
     * All four measurements are recorded on `SetRequestLocale` — in its class
     * docblock and again in `acceptableLanguages()`: `en;q=0` → `de` (below),
     * `en;q=0,de;q=1` → `de`, `de;q=0,en;q=1` → `en`, and `de;q=0` → `de`.
     * The last one is the case a "not used" name would have claimed and been
     * wrong about.
     *
     * Measured before this test existed: Symfony's own
     * `Request::getPreferredLanguage()` returns `en` for `Accept-Language: en;q=0`,
     * because it ignores the weight entirely. Honouring the refusal is the one
     * case where using the framework helper as-is would answer a client in the
     * language it just said it does not want.
     *
     * So the property under test is the removal from the CANDIDATE list —
     * which is what makes this the single red of "delete the filter" in the
     * class docblock's mutation list. The German it then lands on is the
     * DEFAULT, and that half is pinned directly by
     * `test_a_request_that_names_no_language_gets_german`.
     */
    public function test_a_refused_language_is_dropped_from_the_candidate_list(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson(
                '/api/admin/applications/'.$this->approvedApplication()->id.'/resend',
                [],
                ['Accept-Language' => 'en;q=0'],
            )
            ->assertOk()
            ->assertJsonPath('message', self::DE_QUEUED);
    }

    /**
     * The body depends on the request's language, so any intermediary cache has
     * to key on it. Without `Vary` a shared cache would hand the German body to
     * an English client — a correctness bug, not a cosmetic one.
     */
    public function test_the_response_declares_it_varies_by_language(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/applications/'.$this->approvedApplication()->id.'/resend')
            ->assertOk()
            ->assertHeader('Vary', 'Accept-Language');
    }

    /* ---------------------------------------------------------------------
     | The claim survives translation
     | ------------------------------------------------------------------- */

    /**
     * `MandantMailerService::send()` only dispatches `SendMandantMail`, so no
     * endpoint here can know whether the relay answered. The wording may say
     * QUEUED and never SENT — in EVERY language, which is why this is a loop
     * over the catalogs rather than one assertion on the German string.
     *
     * The mutation: rewriting `lang/en/mails.php`'s `queued` as "E-mail has been
     * sent again." turns exactly this test red and nothing else in the suite.
     */
    public function test_no_locale_claims_the_mail_was_sent(): void
    {
        foreach (['de', 'en'] as $locale) {
            $message = __('mails.queued', locale: $locale);

            // `gesendet`/`sent` as a past claim. "E-Mail" itself contains no
            // such word, and neither catalog uses "send" as a noun here.
            $this->assertDoesNotMatchRegularExpression(
                '/\b(gesendet|sent)\b/i',
                $message,
                "the {$locale} catalog must not claim delivery: the endpoint only queues a job",
            );
        }
    }

    /* ---------------------------------------------------------------------
     | The 422 bodies
     | ------------------------------------------------------------------- */

    /**
     * The 422s were English literals sitting in German files. The SPA maps 422
     * to a localized sentence of its own (`resendMailUtils`), so the server text
     * is what a non-SPA client reads — a user-facing string like any other.
     */
    public function test_the_422_bodies_are_localized_too(): void
    {
        $requested = $this->requestedApplication();
        $endpoint = '/api/admin/applications/'.$requested->id.'/resend';

        // Per-request headers, NOT `withHeader()`: that one writes into
        // `defaultHeaders` and STICKS for the rest of the test, so the German
        // request below would silently carry the English header set above and
        // assert English against a German expectation — green for the wrong
        // reason. Passing `$headers` to `postJson` scopes them to one call.
        $this->actingAsApi($this->superAdmin())
            ->postJson($endpoint, [], ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('mails.no_mailable_status', locale: 'en'));

        $this->actingAsApi($this->superAdmin())
            ->postJson($endpoint, [], ['Accept-Language' => 'de'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('mails.no_mailable_status', locale: 'de'));

        // And the German expectation is not the English one in disguise.
        $this->assertNotSame(
            __('mails.no_mailable_status', locale: 'de'),
            __('mails.no_mailable_status', locale: 'en'),
        );
    }

    /**
     * The two resend endpoints reject for the SAME reasons on DIFFERENT rows, so
     * the sub body must name the sub-application. Sharing the key would tell an
     * English admin his sub-row is an "application".
     */
    public function test_the_sub_422_names_the_sub_row_and_is_localized(): void
    {
        $row = $this->requestedSubApplication();
        $endpoint = '/api/admin/sub-applications/'.$row->id.'/resend';

        // Per-request headers, not `withHeader()` — see the sibling test for why
        // that distinction decides whether this test can pass for the wrong reason.
        $this->actingAsApi($this->superAdmin())
            ->postJson($endpoint, [], ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('mails.sub_no_mailable_status', locale: 'en'));

        $this->actingAsApi($this->superAdmin())
            ->postJson($endpoint, [], ['Accept-Language' => 'de'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('mails.sub_no_mailable_status', locale: 'de'));

        // The sub body must not borrow the MAIN row's wording: the two endpoints
        // reject for the same reasons on different rows, so "this application has
        // no mailable status" would be the wrong noun in both languages.
        $this->assertStringContainsStringIgnoringCase('sub', __('mails.sub_no_mailable_status', locale: 'en'));
        $this->assertStringContainsStringIgnoringCase('sub', __('mails.sub_no_mailable_status', locale: 'de'));
        $this->assertStringContainsStringIgnoringCase('sub', __('mails.sub_no_mailable_reason', locale: 'en'));
        $this->assertStringContainsStringIgnoringCase('sub', __('mails.sub_no_mailable_reason', locale: 'de'));
    }

    /* ---------------------------------------------------------------------
     | Catalog parity
     | ------------------------------------------------------------------- */

    /**
     * Every key the German catalog defines must exist in English too, and vice
     * versa. Laravel's translator answers a MISSING key with the key itself
     * (`mails.queued`), so an incomplete catalog ships a raw dotted string into
     * the UI — and the DE/EN requests would both "work" while one of them shows
     * `mails.queued` to an admin.
     *
     * The inverse check matters as much: an EN-only key would be invisible in a
     * DE-only walk and just as broken in reverse.
     */
    public function test_both_catalogs_define_exactly_the_same_keys(): void
    {
        $de = require lang_path('de/mails.php');
        $en = require lang_path('en/mails.php');

        $this->assertSame(
            array_keys($de),
            array_keys($en),
            'the DE and EN catalogs must define the same keys in the same order',
        );

        foreach ($de as $key => $value) {
            $this->assertIsString($value);
            $this->assertNotSame('', trim($value), "mails.{$key} is empty in DE");
            $this->assertNotSame('', trim($en[$key]), "mails.{$key} is empty in EN");
        }
    }

    /**
     * `SetRequestLocale::SUPPORTED_LOCALES` is the negotiation's whole input, so
     * a locale added there without its `lang/<locale>/` directory would answer
     * with `mails.queued` instead of a sentence. The list and the disk must
     * agree.
     */
    public function test_every_supported_locale_has_a_catalog_on_disk(): void
    {
        foreach (SetRequestLocale::SUPPORTED_LOCALES as $locale) {
            $this->assertFileExists(
                lang_path($locale.'/mails.php'),
                "SetRequestLocale supports '{$locale}' but lang/{$locale}/mails.php does not exist",
            );
        }
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * The id of a row the given surface can act on successfully, so the two
     * locale tests per surface differ in NOTHING but the header.
     */
    private function rowId(string $kind): int
    {
        return match ($kind) {
            'main' => $this->approvedApplication()->id,
            'sub' => $this->approvedSubApplication()->id,
            'dlq' => $this->failedMail()->id,
        };
    }

    private function approvedApplication(): Application
    {
        return $this->application(['status' => 'approved']);
    }

    private function requestedApplication(): Application
    {
        return $this->application(['status' => 'requested']);
    }

    private function application(array $attributes): Application
    {
        return Application::create([
            'accreditation_id' => $this->accreditation()->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'requested',
            'priority' => false,
            ...$attributes,
        ]);
    }

    private function approvedSubApplication(): SubApplication
    {
        return $this->subApplication(['status' => 'approved']);
    }

    private function requestedSubApplication(): SubApplication
    {
        return $this->subApplication(['status' => 'requested']);
    }

    private function subApplication(array $attributes): SubApplication
    {
        $applicant = User::factory()->create();
        $accreditation = $this->accreditation();

        // A sub-application is one row per APPROVED main application:
        // `sub_applications.application_id` is NOT NULL and D9 allows no other
        // shape. Mirrors `AdminSubApplicationResendTest::subRequest()`.
        $main = Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $applicant->id,
            'status' => 'approved',
            'priority' => false,
        ]);

        return SubApplication::create([
            'sub_accreditation_id' => $this->subAccreditation($accreditation)->id,
            'application_id' => $main->id,
            'user_id' => $applicant->id,
            'status' => 'requested',
            'priority' => false,
            ...$attributes,
        ]);
    }

    /**
     * A real dead letter: the requeue route checks `isMailJob()` on the payload,
     * so a row whose payload is not a `SendMandantMail` would 404 and the test
     * would pass for the wrong reason. Mirrors `MailDeadLetterTest`'s fixture.
     */
    private function failedMail(): FailedJob
    {
        $job = new SendMandantMail($this->mandant->id, (new PlainTestMailable)->to('victim@example.test'));
        $uuid = (string) Str::uuid();

        $id = DB::table('failed_jobs')->insertGetId([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'mandant_id' => $this->mandant->id,
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => SendMandantMail::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 5,
                'data' => [
                    'commandName' => SendMandantMail::class,
                    'command' => serialize($job),
                ],
            ]),
            'exception' => 'TransportException: connection refused',
            'failed_at' => now(),
        ]);

        return FailedJob::query()->findOrFail($id);
    }

    private function accreditation(): Accreditation
    {
        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$seq),
        ]);

        return $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
        ]);
    }

    private function subAccreditation(Accreditation $accreditation): SubAccreditation
    {
        return $accreditation->subAccreditations()->create([
            'type' => 'park',
            'quota' => 5,
        ]);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail()->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $user;
    }
}
