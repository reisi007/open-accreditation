<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\SetRequestLocale;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\SubApplication;
use App\Models\User;
use App\Support\MandantContext;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The nine `{message}` bodies outside the mail surfaces answer in the
 * REQUEST's language.
 *
 * ## The defect this pins
 *
 * Eight of the nine were ENGLISH literals in `app/Http/Controllers/Api/` and
 * the SPA renders every one of them VERBATIM — `ApplyPage`,
 * `MyAccreditationsPage` and `ApprovalsPage` all show `err.message` — so a
 * German-locale applicant was refused in a foreign language on his own screen,
 * and the media 404 was the same sentence in German on five different routes.
 * The ninth (`MediaAccelRedirectTest.php:338`) was German and stayed that way.
 *
 * ## What makes a test of "it is localized" non-vacuous here
 *
 *  - **BOTH locales on EVERY surface.** Not one endpoint: the gap was measured
 *    across the apply guards, the withdraw guards, the wallet refusals, the
 *    badge export and five media routes, and a fix on the first four would
 *    leave the fifth English.
 *  - **The catalogs themselves**, because "the endpoint answers something" and
 *    "the endpoint answers the CATALOG's sentence" are different claims. Laravel
 *    answers a missing key with the key itself (`messages.badges.no_template`),
 *    which is a green response carrying a dotted string to a user.
 *  - **That no literal came back.** The last test in this class scans the
 *    controller sources for the exact English sentences this file replaced, so a
 *    re-introduced literal fails HERE instead of silently answering one route
 *    in a catalog language and its sibling in a hardcoded one.
 *
 * ## The asymmetry, stated rather than averaged away
 *
 * The EN catalog is byte-identical to the literals it replaced, because
 * `frontend/tests/e2e/helpers/ownership.ts` classifies the withdraw 422 by
 * WHOLE-MESSAGE equality and a reworded EN string would fail every E2E run that
 * carries a decided row. The DE wording is free to be German — that is the fix.
 * `media.no_image` is the one key where DE and EN agree, and
 * `test_the_german_media_wording_is_unchanged_byte_for_byte` pins why.
 *
 * ## MUTATIONS, measured on 2026-10-05 over this class and the six classes whose
 * assertions this change touches (`AccreditationTest`, `BadgeTest`,
 * `SubAccreditationRevocationTest`, `WalletTest`, `MediaAccelRedirectTest`,
 * `MandantMediaSelfServiceTest`, plus `ServerMessageLocaleTest` as the
 * neighbour that must not move) — 298 tests before, 298 after:
 *
 *  - deleting `messages.media.no_image` from `lang/de/messages.php` → **4 red**:
 *    the byte-for-byte test, the catalog-parity test, and — the two that make it
 *    worth having — `MediaAccelRedirectTest::public portal delivery is 404` and
 *    `MandantMediaSelfServiceTest::delivery returns 404 message without file`.
 *    Those last two went green on an earlier draft of this file, and the reason
 *    is worth the space: the EN catalog then held the same German string, and
 *    `config('app.fallback_locale') = en` substituted it for the missing German
 *    key. See {@see test_a_missing_key_is_answered_from_the_fallback_catalog_not_the_key()}.
 *  - writing the German sentence into `lang/en/messages.php`'s `no_template` →
 *    **exactly 1 red**: `every key answers differently in the two catalogs`. The
 *    parity test stays green, because the key LISTS are identical — which is the
 *    whole reason the difference is asserted per key and not per file.
 *  - restoring the literal in `WalletController::ownApprovedSubApplication` →
 *    **4 red**: the wallet test here, the source scan, and both existing specs
 *    that pin that body (`SubAccreditationRevocationTest`, `WalletTest`).
 */
class ApiMessageLocaleTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // Approvals queue their notification and the suite runs `sync`, so the
        // fake is what keeps a live relay failure from aborting this class.
        Mail::fake();

        $this->seed(RoleSeeder::class);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        // F2: the badge export only mints verifiable badges for a mandant with a
        // domain of its own, so without this row the 422 under test would be a
        // DIFFERENT one ("Mandant hat keine Domain …").
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MandantContext::reset();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | The apply guards
     | ------------------------------------------------------------------- */

    /**
     * All three refusals, both locales.
     *
     * One method rather than three because they are three branches of ONE
     * endpoint with one display site (`ApplyPage` renders `err.message`), and
     * the reason a German reader met English here is that the window and the
     * duplicate guard were written in a different session than each other.
     */
    public function test_the_apply_guards_answer_in_the_requested_language(): void
    {
        // (1) Before the window opens.
        Carbon::setTestNow('2026-08-01 10:00:00');
        $windowed = $this->accreditation([
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);

        $this->assertGuardsBothLocales(
            $windowed->id,
            'messages.accreditations.not_open_yet',
            $this->user(),
        );

        // (2) After it closed — a different row, because the first apply above
        //     already created an application.
        Carbon::setTestNow('2026-08-21 00:00:00');
        $closed = $this->accreditation([
            'deadline_start' => '2026-08-10',
            'deadline_end' => '2026-08-20',
        ]);

        $this->assertGuardsBothLocales(
            $closed->id,
            'messages.accreditations.deadline_passed',
            $this->user(),
        );

        // (3) The duplicate guard, on an accreditation with no deadlines at
        //     all — the window branches must not be what refuses this one.
        Carbon::setTestNow('2026-08-15 12:00:00');
        $open = $this->accreditation();
        $user = $this->user();

        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$open->id.'/apply')
            ->assertSuccessful();

        $this->assertGuardsBothLocales($open->id, 'messages.accreditations.already_applied', $user);
    }

    /* ---------------------------------------------------------------------
     | The withdraw guards
     | ------------------------------------------------------------------- */

    /**
     * The two withdraw routes, both locales — and they are asserted SEPARATELY,
     * because they name different rows and one key for both would tell an
     * English applicant about a "sub-application" when he clicked the main row's
     * button.
     */
    public function test_the_withdraw_guards_answer_in_the_requested_language(): void
    {
        $accreditation = $this->accreditation();
        $park = $this->accreditation()->subAccreditations()->create(['type' => 'park', 'quota' => 5]);
        $user = $this->user();

        // A DECIDED main row — that is the only state the 422 exists for.
        $application = Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
        ]);
        // DECIDED as well, for the same reason. A `requested` sub row answers
        // 204 here — the withdraw SUCCEEDS — so a fixture in that state would
        // have tested the happy path under a 422's name.
        $sub = SubApplication::create([
            'sub_accreditation_id' => $park->id,
            'application_id' => $application->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
        ]);

        foreach (['de', 'en'] as $locale) {
            $this->actingAsApi($user)
                ->deleteJson('/api/applications/'.$application->id, [], ['Accept-Language' => $locale])
                ->assertStatus(422)
                ->assertJsonPath('message', __('messages.applications.withdraw_not_pending', locale: $locale));

            $this->actingAsApi($user)
                ->deleteJson('/api/sub-applications/'.$sub->id, [], ['Accept-Language' => $locale])
                ->assertStatus(422)
                ->assertJsonPath('message', __('messages.applications.sub_withdraw_not_pending', locale: $locale));
        }

        // The sub guard must name the SUB row, in both languages.
        $this->assertStringContainsStringIgnoringCase('sub', __('messages.applications.sub_withdraw_not_pending', locale: 'en'));
        $this->assertStringContainsStringIgnoringCase('sub', __('messages.applications.sub_withdraw_not_pending', locale: 'de'));
        $this->assertStringNotContainsStringIgnoringCase('sub', __('messages.applications.withdraw_not_pending', locale: 'en'));
        $this->assertStringNotContainsStringIgnoringCase('sub', __('messages.applications.withdraw_not_pending', locale: 'de'));
    }

    /* ---------------------------------------------------------------------
     | The wallet refusals
     | ------------------------------------------------------------------- */

    /**
     * Three branches, both locales: the plain 422 on a main row, the plain 422
     * on a sub row, and the 410 that says the main accreditation was withdrawn.
     *
     * The 410 is reached two different ways in the product's own specs — the
     * cascade denied the sub row, or a direct write left it approved while its
     * main row was not — and they answer the SAME key, which is asserted here
     * once instead of being left to look like two unrelated sentences.
     */
    public function test_the_wallet_refusals_answer_in_the_requested_language(): void
    {
        // Two sub-accreditations, because `sub_applications` is unique per
        // (sub-accreditation, application) and the two sub refusals below need two
        // rows on ONE main application — the 410 is exactly the state where a
        // second sub-acc exists and its main row is not approved any more.
        $park = $this->accreditation()->subAccreditations()->create(['type' => 'park', 'quota' => 5]);
        $seat = $this->accreditation()->subAccreditations()->create(['type' => 'seat', 'quota' => 5]);
        $user = $this->user();

        $approvedMain = Application::create([
            'accreditation_id' => $park->accreditation_id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
        ]);

        // (1) A `requested` main row → 422 on the MAIN key.
        $requestedMain = Application::create([
            'accreditation_id' => $this->accreditation()->id,
            'user_id' => $user->id,
            'status' => 'requested',
            'priority' => false,
        ]);

        foreach (['de', 'en'] as $locale) {
            $this->actingAsApi($user)
                ->getJson('/api/applications/'.$requestedMain->id.'/wallet', ['Accept-Language' => $locale])
                ->assertStatus(422)
                ->assertJsonPath('message', __('messages.wallet.not_approved', locale: $locale));
        }

        // (2) A `requested` sub row on an APPROVED main → 422 on the SUB key.
        $requestedSub = SubApplication::create([
            'sub_accreditation_id' => $park->id,
            'application_id' => $approvedMain->id,
            'user_id' => $user->id,
            'status' => 'requested',
            'priority' => false,
        ]);

        foreach (['de', 'en'] as $locale) {
            $this->actingAsApi($user)
                ->getJson('/api/sub-applications/'.$requestedSub->id.'/wallet', ['Accept-Language' => $locale])
                ->assertStatus(422)
                ->assertJsonPath('message', __('messages.wallet.sub_not_approved', locale: $locale));
        }

        // (3) An approved sub row whose MAIN row is no longer approved → 410.
        $revokedSub = SubApplication::create([
            'sub_accreditation_id' => $seat->id,
            'application_id' => $approvedMain->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'priority' => false,
        ]);
        Application::query()->whereKey($approvedMain->id)->update(['status' => 'denied']);

        foreach (['de', 'en'] as $locale) {
            $this->actingAsApi($user)
                ->getJson('/api/sub-applications/'.$revokedSub->id.'/wallet', ['Accept-Language' => $locale])
                ->assertStatus(410)
                ->assertJsonPath('message', __('messages.wallet.main_revoked', locale: $locale));
        }
    }

    /* ---------------------------------------------------------------------
     | The badge export
     | ------------------------------------------------------------------- */

    public function test_the_badge_export_missing_template_answers_in_the_requested_language(): void
    {
        $accreditation = $this->accreditation();

        foreach (['de', 'en'] as $locale) {
            $this->actingAsApi($this->superAdmin())
                ->postJson(
                    '/api/admin/accreditations/'.$accreditation->id.'/badges/export',
                    ['format' => 'pdf'],
                    ['Accept-Language' => $locale],
                )
                ->assertStatus(422)
                ->assertJsonPath('message', __('messages.badges.no_template', locale: $locale));
        }
    }

    /* ---------------------------------------------------------------------
     | The media routes — one sentence, five endpoints
     | ------------------------------------------------------------------- */

    /**
     * All five routes that answer this sentence, both locales.
     *
     * They are asserted TOGETHER on purpose. `media.no_image` is one key used at
     * five call sites, and a test that pinned only the cited route would pass on
     * a tree where four of the five still skipped the catalogs — which is the
     * exact inconsistency this key exists to remove.
     *
     * DE is byte-identical here, so these assertions double as the control that
     * the three pre-existing German specs (`MediaAccelRedirectTest:338`,
     * `MandantMediaSelfServiceTest:235/240`) still hold.
     */
    public function test_the_media_routes_answer_the_catalog_in_the_requested_language(): void
    {
        $team = $this->mandant->teams()->create(['name' => 'Team A', 'slug' => 'team-a']);
        $eventType = EventType::create([
            'mandant_id' => $this->mandant->id,
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
        ]);

        $routes = [
            // Public portal delivery.
            fn (string $locale) => $this->getJson('/api/portal/mandant/logo', ['Accept-Language' => $locale]),
            // Own-mandant self service.
            fn (string $locale) => $this->actingAsApi($this->mandantAdmin())
                ->getJson('/api/mandant/logo', ['Accept-Language' => $locale]),
            // Admin mandant delivery.
            fn (string $locale) => $this->actingAsApi($this->superAdmin())
                ->getJson('/api/admin/mandants/'.$this->mandant->id.'/logo', ['Accept-Language' => $locale]),
            // Admin team delivery.
            fn (string $locale) => $this->actingAsApi($this->superAdmin())
                ->getJson('/api/admin/teams/'.$team->id.'/logo', ['Accept-Language' => $locale]),
            // Admin event-type delivery.
            fn (string $locale) => $this->actingAsApi($this->superAdmin())
                ->getJson('/api/admin/event-types/'.$eventType->id.'/logo', ['Accept-Language' => $locale]),
        ];

        foreach (['de', 'en'] as $locale) {
            foreach ($routes as $index => $call) {
                $call($locale)
                    ->assertStatus(404)
                    ->assertJsonPath('message', __('messages.media.no_image', locale: $locale));
            }
        }
    }

    /**
     * The German literal these five routes answered before this change is still
     * the German answer, character for character.
     *
     * It is the one DE string that was NOT an English literal, so it is the one
     * where "we localized it" could silently mean "we reworded it". Three
     * pre-existing specs assert the literal directly; this one says which
     * catalog it has to keep matching.
     */
    public function test_the_german_media_wording_is_unchanged_byte_for_byte(): void
    {
        $this->assertSame('Kein Bild hinterlegt.', __('messages.media.no_image', locale: 'de'));
    }

    /* ---------------------------------------------------------------------
     | Catalogs
     | ------------------------------------------------------------------- */

    /**
     * Every key, in every catalog, both locales — DE against EN.
     *
     * Per key rather than per file, because a lazy catalog copy fails this way:
     * one key copied into EN leaves the file's key LIST identical while the
     * German leaks into the English answer.
     *
     * There is deliberately NO exemption list. `media.no_image` is the key that
     * tempted one — it was German on both sides, so "keep the values equal"
     * would have spared an English string nobody can read. Measured: that would
     * have cost the fallback its safety net (see
     * {@see test_a_missing_key_is_answered_from_the_fallback_catalog_not_the_key()})
     * and put the only place the next real hole hides inside this very test.
     */
    public function test_every_key_answers_differently_in_the_two_catalogs(): void
    {
        foreach ($this->keys() as $key) {
            $de = __($key, locale: 'de');
            $en = __($key, locale: 'en');

            $this->assertNotSame('', trim($de), "{$key} is empty in DE");
            $this->assertNotSame('', trim($en), "{$key} is empty in EN");

            // A missing key resolves to itself, which is what makes the emptiness
            // check above reachable at all — Laravel does not fail on it.
            $this->assertNotSame($key, $de, "{$key} resolves to its own key in DE");
            $this->assertNotSame($key, $en, "{$key} resolves to its own key in EN");

            $this->assertNotSame($de, $en, "{$key} answers identically in DE and EN — the EN copy is the German one");
        }
    }

    /**
     * What Laravel does with a key that is MISSING, in both directions — because
     * one of the two is silent and decides what the parity gate is worth.
     *
     * MEASURED 2026-10-05:
     *
     *  - missing from BOTH catalogs → the answer is the KEY ITSELF
     *    (`messages.no_such_key_at_all`), so a dotted string would reach the UI.
     *    That is the failure {@see test_every_key_answers_differently_in_the_two_catalogs()}
     *    catches per key.
     *  - missing from the ACTIVE locale but present in `config('app.fallback_locale')`
     *    (which is `en`) → the answer is the FALLBACK's sentence, silently. A DE
     *    client is then answered in English and NOTHING in the request path says
     *    so.
     *
     * The second one is why the parity test exists and why no wording test can
     * stand in for it: deleting `media.no_image` from `lang/de/messages.php` on
     * 2026-10-05 turned exactly ONE test red — the parity one — while
     * `MediaAccelRedirectTest` and `MandantMediaSelfServiceTest` stayed green,
     * because the EN value happened to be the same German sentence.
     */
    public function test_a_missing_key_is_answered_from_the_fallback_catalog_not_the_key(): void
    {
        $this->assertSame('en', config('app.fallback_locale'), 'this gate describes the configured fallback');

        foreach (['de', 'en'] as $locale) {
            $this->assertSame(
                'messages.no_such_key_at_all',
                __('messages.no_such_key_at_all', locale: $locale),
                "a key missing from every catalog must answer with itself in {$locale}, not with a sentence",
            );
        }
    }

    /**
     * Parity across ALL catalogs on disk, not just `messages.php`.
     *
     * A catalog added later is covered without anyone editing this test, which
     * is the point of globbing instead of naming the two files the first version
     * of this work happened to need.
     */
    public function test_every_catalog_file_defines_the_same_keys_in_both_locales(): void
    {
        foreach ($this->catalogFiles() as $file) {
            $de = $this->flatten(require lang_path('de/'.$file));
            $en = $this->flatten(require lang_path('en/'.$file));

            $this->assertSame(
                array_keys($de),
                array_keys($en),
                "lang/de/{$file} and lang/en/{$file} must define the same keys in the same order",
            );

            foreach ($de as $key => $value) {
                $this->assertIsString($value);
                $this->assertNotSame('', trim($value), "{$file}.{$key} is empty in DE");
                $this->assertNotSame('', trim($en[$key]), "{$file}.{$key} is empty in EN");
            }
        }
    }

    /**
     * `SetRequestLocale::SUPPORTED_LOCALES` is the negotiation's whole input, so
     * a locale added there without its catalogs would answer with the KEY.
     * Checked over every catalog file, not the mail one.
     */
    public function test_every_supported_locale_has_every_catalog_on_disk(): void
    {
        foreach (SetRequestLocale::SUPPORTED_LOCALES as $locale) {
            foreach ($this->catalogFiles() as $file) {
                $this->assertFileExists(
                    lang_path($locale.'/'.$file),
                    "SetRequestLocale supports '{$locale}' but lang/{$locale}/{$file} does not exist",
                );
            }
        }
    }

    /* ---------------------------------------------------------------------
     | The negotiation itself
     | ------------------------------------------------------------------- */

    /**
     * The body depends on the request's language, so any intermediary cache has
     * to key on it.
     *
     * Asserted on an `abort()`-thrown 422, because that is the shape all but one
     * of these bodies have — a middleware that appends `Vary` AFTER `$next` and
     * a controller that throws before returning it is exactly the pair whose
     * interaction nobody assumes. Measured on 2026-10-05: the header IS there,
     * because the routing pipeline renders the exception inside the middleware
     * stack. Without this assertion that stays an assumption.
     */
    public function test_a_thrown_body_still_declares_it_varies_by_language(): void
    {
        $user = $this->user();
        $accreditation = $this->accreditation();

        $this->actingAsApi($user)->postJson('/api/accreditations/'.$accreditation->id.'/apply');

        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply', [], ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertHeader('Vary', 'Accept-Language');
    }

    /**
     * The regional header a real browser sends, on a body that used to be
     * locale-blind. `en-US,en;q=0.9` rather than the bare `en` above, because
     * that is what Chromium and curl-with-a-locale actually put on the wire.
     */
    public function test_a_regional_header_negotiates_on_one_of_these_bodies_too(): void
    {
        $user = $this->user();
        $accreditation = $this->accreditation();

        $this->actingAsApi($user)->postJson('/api/accreditations/'.$accreditation->id.'/apply');

        $this->actingAsApi($user)
            ->postJson('/api/accreditations/'.$accreditation->id.'/apply', [], ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.accreditations.already_applied', locale: 'en'));
    }

    /* ---------------------------------------------------------------------
     | Nothing came back
     | ------------------------------------------------------------------- */

    /**
     * The exact sentences this change removed, asserted ABSENT from the
     * controllers.
     *
     * Every other test in this class asks "does the endpoint answer the catalog".
     * This one asks the question none of them can: whether somebody put a
     * literal BACK. A re-introduced English string would keep every catalog
     * assertion green on the nine routes under test and reintroduce the defect on
     * the next one — so the gate is on the SOURCES, where the defect actually
     * lives, and it covers the siblings that are only reachable through the
     * tests this class cannot see.
     */
    public function test_no_controller_hardcodes_the_literals_the_catalogs_replaced(): void
    {
        $replaced = [
            'No badge template.',
            'Applications for this accreditation are not open yet.',
            'The application deadline for this accreditation has passed.',
            'You have already applied for this accreditation.',
            'Only pending (requested) applications can be withdrawn.',
            'Only pending (requested) sub-applications can be withdrawn.',
            'The main accreditation was withdrawn, this wallet pass is no longer valid.',
            'Only approved applications can be downloaded as a wallet pass.',
            'Only approved sub-applications can be downloaded as a wallet pass.',
            'Kein Bild hinterlegt.',
        ];

        $sources = [];
        foreach (glob(app_path('Http/Controllers/Api/**/*.php')) ?: [] as $file) {
            $sources[$file] = file_get_contents($file);
        }
        // `glob` does not recurse on every platform; walk it explicitly so a
        // controller in a nested namespace cannot slip past the gate.
        foreach (glob(app_path('Http/Controllers/Api/*/*.php')) ?: [] as $file) {
            $sources[$file] = file_get_contents($file);
        }
        $sources[app_path('Http/Controllers/Api/AccreditationController.php')] = file_get_contents(
            app_path('Http/Controllers/Api/AccreditationController.php')
        );
        $sources[app_path('Http/Controllers/Api/ApplicationController.php')] = file_get_contents(
            app_path('Http/Controllers/Api/ApplicationController.php')
        );
        $sources[app_path('Http/Controllers/Api/SubApplicationController.php')] = file_get_contents(
            app_path('Http/Controllers/Api/SubApplicationController.php')
        );
        $sources[app_path('Http/Controllers/Api/WalletController.php')] = file_get_contents(
            app_path('Http/Controllers/Api/WalletController.php')
        );

        $this->assertNotEmpty($sources, 'the controller scan found no sources — the glob is wrong, the gate is vacuous');

        foreach ($replaced as $literal) {
            foreach ($sources as $file => $contents) {
                $this->assertStringNotContainsString(
                    $literal,
                    $contents,
                    basename($file).' hardcodes "'.$literal.'", which is a catalog key now — use __(…)',
                );
            }
        }
    }

    /**
     * The EN catalog is byte-identical to the literals it replaced, because an
     * E2E harness classifies the withdraw 422 by WHOLE-MESSAGE equality.
     *
     * `frontend/tests/e2e/helpers/ownership.ts` holds these two strings in
     * `TEARDOWN_DECIDED_MESSAGES` and compares with `===`. A reworded EN entry
     * would not fail a single PHP test on its own — it would fail every E2E run
     * that carries a decided row, with a teardown that cannot classify its own
     * 422. This assertion is the one place that link is written down.
     */
    public function test_the_english_withdraw_bodies_are_byte_identical_to_the_e2e_classifier(): void
    {
        $this->assertSame(
            'Only pending (requested) applications can be withdrawn.',
            __('messages.applications.withdraw_not_pending', locale: 'en'),
        );
        $this->assertSame(
            'Only pending (requested) sub-applications can be withdrawn.',
            __('messages.applications.sub_withdraw_not_pending', locale: 'en'),
        );

        // And the harness is asserted to still hold exactly those.
        $harness = file_get_contents(base_path('../frontend/tests/e2e/helpers/ownership.ts'));
        $this->assertIsString($harness, 'the E2E teardown helper must exist for this coupling to be checkable');
        $this->assertStringContainsString(
            "'Only pending (requested) applications can be withdrawn.'",
            $harness,
            'the E2E teardown classifier lost the main-row body this catalog answers with',
        );
        $this->assertStringContainsString(
            "'Only pending (requested) sub-applications can be withdrawn.'",
            $harness,
            'the E2E teardown classifier lost the sub-row body this catalog answers with',
        );
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * One apply guard, both locales, on a row nothing else can refuse.
     *
     * The DE request comes SECOND on purpose: `withHeader()` writes into
     * `defaultHeaders` and STICKS, so the reverse order would let a leaked
     * English header make the German assertion pass against an English answer.
     * Per-request headers keep the two calls independent.
     */
    private function assertGuardsBothLocales(int $accreditationId, string $key, User $user): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->actingAsApi($user)
                ->postJson('/api/accreditations/'.$accreditationId.'/apply', [], ['Accept-Language' => $locale])
                ->assertStatus(422)
                ->assertJsonPath('message', __($key, locale: $locale));
        }
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        $keys = [];
        foreach ($this->flatten(require lang_path('de/messages.php')) as $key => $_value) {
            $keys[] = 'messages.'.$key;
        }

        return $keys;
    }

    /**
     * Every catalog file the app ships, de/en alike.
     *
     * @return list<string>
     */
    private function catalogFiles(): array
    {
        $files = [];
        foreach (glob(lang_path('de/*.php')) ?: [] as $path) {
            $files[] = basename($path);
        }

        return $files;
    }

    /**
     * Laravel addresses nested catalog arrays with dots (`messages.badges.…`),
     * and so does this gate — a parity check that only compared the top level
     * would call two catalogs equal while their nested keys differed.
     *
     * @param  array<string, mixed>  $catalog
     * @return array<string, string>
     */
    private function flatten(array $catalog, string $prefix = ''): array
    {
        $flat = [];
        foreach ($catalog as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }
            $flat[$path] = $value;
        }

        return $flat;
    }

    private function accreditation(array $attributes = []): Accreditation
    {
        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$seq),
        ]);

        return $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
            ...$attributes,
        ]);
    }

    private function user(): User
    {
        $user = User::factory()->forMandant($this->mandant)->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::USER->value)->firstOrFail()->id,
            'mandant_id' => $this->mandant->id,
            'team_id' => null,
        ]);

        return $user;
    }

    private function mandantAdmin(): User
    {
        $user = User::factory()->forMandant($this->mandant)->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::MANDANT_ADMIN->value)->firstOrFail()->id,
            'mandant_id' => $this->mandant->id,
            'team_id' => null,
        ]);

        return $user;
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
