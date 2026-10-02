<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\BadgeTemplate;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Models\UserMedia;
use App\Services\MediaHostResolver;
use App\Services\QrTokenService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WP-2 / R-D3 — the QR verification token, format v2.
 *
 * v2 = `base64url(id . hmac(secret, "v2:"+id+":"+mandantId) . mandantId)`: the
 * signature covers BOTH claims, so a token is bound to the mandant that issued
 * it. This suite pins the three properties that were broken before:
 *
 * 1. **Tenant binding** — a token minted for mandant A neither resolves nor
 *    leaks any holder data on mandant B's host, and a tampered `mandant_id`
 *    segment is rejected.
 * 2. **Legacy (v1) compatibility** — a two-segment token whose HMAC verifies
 *    against a known key still resolves, but it carries no tenant claim: the
 *    mandant-scoped lookup of `VerifyController` is (and must stay) its
 *    isolation boundary.
 * 3. **Key-rotation self-healing** — a stored token stays valid while its key
 *    is listed in `APP_PREVIOUS_KEYS`, and `make()` re-mints it (updating the
 *    row) when it no longer verifies against any known key.
 */
class QrTokenV2Test extends TestCase
{
    use RefreshDatabase;

    private const NEW_KEY = 'base64:0cW0kZm9vdHM5bm90aGluZ0hlcmVGb3JUaGVXZXk=';

    /**
     * Base key of the ambiguous legacy-signature fixture. Under a key whose HMAC
     * of an application id ends in a '.' byte with an all-digit tail ("…d2e.8"),
     * a v1 token is indistinguishable from a v2 token by segment shape alone.
     *
     * The ambiguity is a property of the PAIR (application id, key) — and the
     * application id is ENGINE-DEPENDENT: SQLite's AUTOINCREMENT counter starts
     * over in every fresh in-memory test database, while a Postgres sequence is
     * not transactional and keeps counting across the rolled-back tests of a run
     * (that run reached 711 for the very first application). A hardcoded id would
     * therefore have pinned the fixture to one engine.
     *
     * So the fixture is pinned the other way round: for the id the engine really
     * assigned, `ambiguousLegacyKeyFor()` derives a key that is ambiguous for
     * exactly that id. For SQLite's first application (id 1) the base key
     * already is, so the derived key IS the base key and that run stays
     * byte-for-byte the old one. Bounded, and asserted non-null by the callers,
     * so it cannot silently degrade into "no fixture found".
     */
    private const LEGACY_FIXTURE_KEY = 'wp1-ambiguous-v1-signature-fixture-4809';

    /**
     * Upper bound for the key search in `ambiguousLegacyKeyFor()`. The measured
     * hit rate is ~1 in 6 400 candidates (the same "4 of 40 000" the parser
     * comment cites); the worst value seen over a few hundred ids was ~33 000,
     * and a full miss costs well under a second.
     */
    private const LEGACY_FIXTURE_KEY_SEARCH_LIMIT = 250000;

    private Mandant $mandantA;

    private Mandant $mandantB;

    private static int $categorySeq = 0;

    private static int $mediaCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('private');

        config(['app.previous_keys' => []]);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | Format
     | ------------------------------------------------------------------- */

    public function test_minted_token_is_the_tenant_bound_v2_format(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $key = (string) config('app.key');

        $token = app(QrTokenService::class)->make($application);
        $decoded = (string) base64_decode(strtr($token, '-_', '+/'), true);
        $parts = $this->splitV2Payload($decoded);

        // THE PREMISE, stated instead of inherited: whether THIS id's signature
        // carries a '.' byte decides what a naive explode() would have made of
        // the payload — three segments if it does not, four or more if it does.
        // Roughly 12 % of ids are the dotted kind (see splitV2Payload()) and the
        // id comes from the DB sequence, so this is a fact about this run, not
        // an invariant of the format. Pinned as a RELATION, so it holds for
        // every id; its failure message names the count a naive split produces.
        $this->assertSame(
            3 + substr_count($parts['signature'], '.'),
            $parts['naiveSegmentCount'],
            sprintf(
                'premise: the signature of application %d %s a "." byte, so a naive explode() yields %d segments, not 3 — the claims are read from the outside in',
                $application->id,
                $parts['signatureHasDot'] ? 'carries' : 'does not carry',
                $parts['naiveSegmentCount'],
            ),
        );

        // The claims sit at the two ends and the signature between them covers
        // BOTH (version-marked, so it can never be confused with a v1 signature
        // over the same id). Written as "opens with / ends with" plus the
        // outside-in parts, because that is what the format guarantees for
        // EVERY application id. A segment COUNT is not: this test used to assert
        // `assertCount(3, explode('.', $decoded))`, which is false for ~12 % of
        // ids and went red on Postgres while the product was correct.
        $this->assertStringStartsWith($application->id.'.', $decoded, 'a v2 payload opens with the application id claim');
        $this->assertStringEndsWith('.'.$this->mandantA->id, $decoded, 'a v2 payload ends with the mandant claim');
        $this->assertSame((string) $application->id, $parts['id']);
        $this->assertSame((string) $this->mandantA->id, $parts['mandantId']);

        $this->assertSame(
            hash_hmac('sha256', 'v2:'.$application->id.':'.$this->mandantA->id, $key, true),
            $parts['signature'],
        );

        $claims = app(QrTokenService::class)->parse($token);

        $this->assertNotNull($claims);
        $this->assertSame($application->id, $claims->applicationId);
        $this->assertSame($this->mandantA->id, $claims->mandantId);
        $this->assertSame(2, $claims->version);
        $this->assertTrue($claims->isTenantBound());
        $this->assertTrue($claims->matchesMandant((int) $this->mandantA->id));
        $this->assertFalse($claims->matchesMandant((int) $this->mandantB->id));
    }

    /**
     * The named regression cases for the shape above: application ids whose v2
     * signature really does carry a '.' byte, so that the assertions are pinned
     * for a dotted signature and not only for whatever the sequence happened to
     * hand out.
     *
     * Whether an id is dotted is a property of the PAIR (application id, mandant
     * id) — measured on the signing key of `phpunit.xml:44` under which this
     * suite runs: for mandant 1 the offenders within ids 1..40 are 13, 22, 28,
     * 30 and 40; 11.51 % of ids 1..20000 are dotted. That is why every dataset
     * names BOTH claims, and why the third element states the premise instead of
     * leaving the test to assume it: a changed key or a changed pair is then
     * reported, not silently downgraded to another dot-free case. (The premise
     * assertion below has already earned its keep once: it turned a set of pairs
     * measured against the DEV key red on the first run.)
     *
     * Nothing is written and no sequence is consumed — `QrTokenService::token()`
     * is pure and reads the mandant claim from the preloaded relation — so these
     * ids come from the fixture rather than from the database. That is precisely
     * what the format test above cannot guarantee.
     *
     * @return array<string, array{int, int, bool}>
     */
    public static function tokenShapePairs(): array
    {
        return [
            'application id 2, mandant id 9 (dotted)' => [2, 9, true],
            'application id 8, mandant id 5 (dotted)' => [8, 5, true],
            'application id 14, mandant id 14 (dotted)' => [14, 14, true],
            'application id 27, mandant id 4 (dotted)' => [27, 4, true],
            'application id 35, mandant id 8 (dotted)' => [35, 8, true],
            'application id 40, mandant id 1 (dotted)' => [40, 1, true],
            'application id 1, mandant id 1 (dot-free control)' => [1, 1, false],
        ];
    }

    #[DataProvider('tokenShapePairs')]
    public function test_the_v2_format_survives_a_dotted_signature(int $applicationId, int $mandantId, bool $expectDotted): void
    {
        $service = app(QrTokenService::class);
        $key = (string) config('app.key');

        $application = $this->applicationWithId($applicationId, $mandantId);
        $token = $service->token($application);
        $decoded = (string) base64_decode(strtr($token, '-_', '+/'), true);
        $parts = $this->splitV2Payload($decoded);

        // Announced: this case is dotted (or deliberately is not), and what a
        // naive three-segment split would have concluded about it.
        $this->assertSame($expectDotted, $parts['signatureHasDot'], sprintf(
            'premise: the signature of application %d for mandant %d is expected %s a "." byte under the signing key in use',
            $applicationId,
            $mandantId,
            $expectDotted ? 'to carry' : 'NOT to carry',
        ));

        if ($expectDotted) {
            $this->assertGreaterThan(
                3,
                $parts['naiveSegmentCount'],
                'premise: a dotted signature MUST make a naive explode() yield more than three segments — otherwise this case proves nothing',
            );
        }

        $this->assertStringStartsWith($applicationId.'.', $decoded, 'a v2 payload opens with the application id claim');
        $this->assertStringEndsWith('.'.$mandantId, $decoded, 'a v2 payload ends with the mandant claim');
        $this->assertSame((string) $applicationId, $parts['id']);
        $this->assertSame((string) $mandantId, $parts['mandantId']);
        $this->assertSame(
            hash_hmac('sha256', 'v2:'.$applicationId.':'.$mandantId, $key, true),
            $parts['signature'],
            'the bytes between the two claims are the HMAC over BOTH of them',
        );

        $claims = $service->parse($token);

        $this->assertNotNull($claims, 'the service must read back its own token, dotted signature or not');
        $this->assertSame($applicationId, $claims->applicationId);
        $this->assertSame($mandantId, $claims->mandantId);
        $this->assertTrue($claims->isTenantBound());

        // … and the binding survives the dot: the very same signature carrying a
        // foreign mandant claim must not verify.
        $this->assertNull(
            $service->parse($this->encode($applicationId.'.'.$parts['signature'].'.'.($mandantId + 1))),
            'a dotted signature must not weaken the tenant binding: the mandant claim is covered by the HMAC',
        );
    }

    public function test_a_signature_containing_a_dot_still_parses(): void
    {
        $service = app(QrTokenService::class);
        $key = (string) config('app.key');

        // The raw HMAC is binary, so ~12 % of signatures contain a '.' byte.
        // The parser takes the segments from the outside in, so such a token
        // must not be mistaken for a v2 token with a corrupt mandant claim.
        $legacyId = $this->firstIdWithDottedSignature(static fn (int $id): string => hash_hmac('sha256', (string) $id, $key, true));

        $this->assertGreaterThan(0, $legacyId, 'no dotted v1 signature found — the fixture search is broken');

        $legacyClaims = $service->parse($this->encode($legacyId.'.'.hash_hmac('sha256', (string) $legacyId, $key, true)));
        $this->assertNotNull($legacyClaims);
        $this->assertSame($legacyId, $legacyClaims->applicationId);
        $this->assertNull($legacyClaims->mandantId);

        $v2 = $this->firstIdWithDottedSignature(
            static fn (int $id): string => hash_hmac('sha256', 'v2:'.$id.':1', $key, true),
        );

        $this->assertGreaterThan(0, $v2, 'no dotted v2 signature found — the fixture search is broken');

        $v2Claims = $service->parse($this->encode($v2.'.'.hash_hmac('sha256', 'v2:'.$v2.':1', $key, true).'.1'));
        $this->assertNotNull($v2Claims);
        $this->assertSame($v2, $v2Claims->applicationId);
        $this->assertSame(1, $v2Claims->mandantId);
    }

    /* ---------------------------------------------------------------------
     | Tenant binding
     | ------------------------------------------------------------------- */

    public function test_token_of_mandant_a_does_not_resolve_on_mandant_b(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $this->storePortrait($application->user);
        $token = app(QrTokenService::class)->make($application);

        // The token is perfectly valid — for mandant A.
        $this->assertNotNull(app(QrTokenService::class)->parse($token));

        // On mandant B's host it must resolve to nothing at all, and disclose
        // nothing: no status, no name, no category, no photo URL.
        MandantContext::set($this->mandantB);

        $response = $this->getJson('/api/verify/'.$token)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Invalid verification token.']);

        $response->assertJsonMissingPath('data');
        $response->assertJsonMissingPath('data.name');
        $response->assertJsonMissingPath('data.photo_url');
        $this->assertStringNotContainsString('Jane Doe', $response->getContent());

        // The portrait route is behind the very same resolution.
        $this->getJson('/api/verify/'.$token.'/photo')->assertStatus(404);
    }

    public function test_tampered_mandant_segment_is_rejected(): void
    {
        $service = app(QrTokenService::class);
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $token = $service->make($application);

        $parts = $this->splitV2Payload((string) base64_decode(strtr($token, '-_', '+/'), true));

        // CONTROL: this very payload IS a valid token, so what the assertion
        // below rejects is the swapped CLAIM and nothing else. Without it, a
        // payload that had been mangled on the way here (a naive explode() used
        // to truncate a dotted signature at its first dot) would be rejected for
        // its shape and this test would be green for the wrong reason.
        $this->assertNotNull(
            $service->parse($this->encode($parts['id'].'.'.$parts['signature'].'.'.$parts['mandantId'])),
            'control: the unaltered payload must verify — otherwise the rejection below proves nothing',
        );

        // Mandant A's signature, mandant B's claim: the HMAC no longer covers
        // the claims, so the token is unverifiable.
        $forged = $this->encode($parts['id'].'.'.$parts['signature'].'.'.$this->mandantB->id);

        $this->assertNull(
            $service->parse($forged),
            'a signature that does not cover the mandant claim must not verify — whether or not that signature itself contains a "." byte',
        );
        $this->assertFalse($service->isValidFor($application, $forged));

        $this->getJson('/api/verify/'.$forged)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Invalid verification token.']);

        // … and it is never persisted as a repair, because it does not verify.
        $this->assertNotSame($forged, $service->make($application->fresh()));
    }

    public function test_tampered_application_id_segment_is_rejected(): void
    {
        $service = app(QrTokenService::class);
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $token = $service->make($application);

        $parts = $this->splitV2Payload((string) base64_decode(strtr($token, '-_', '+/'), true));

        // CONTROL, see test_tampered_mandant_segment_is_rejected().
        $this->assertNotNull(
            $service->parse($this->encode($parts['id'].'.'.$parts['signature'].'.'.$parts['mandantId'])),
            'control: the unaltered payload must verify — otherwise the rejection below proves nothing',
        );

        // Only the id claim differs, and the HMAC covers it too.
        $forged = $this->encode(((int) $parts['id'] + 1).'.'.$parts['signature'].'.'.$parts['mandantId']);

        $this->assertNull(
            $service->parse($forged),
            'the id claim is part of the signed message, so a neighbouring id must not verify against this signature',
        );
        $this->getJson('/api/verify/'.$forged)->assertStatus(404);
    }

    public function test_a_corrupt_mandant_segment_falls_back_to_the_legacy_check_and_is_rejected(): void
    {
        $service = app(QrTokenService::class);
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $token = $service->make($application);

        $parts = $this->splitV2Payload((string) base64_decode(strtr($token, '-_', '+/'), true));

        // CONTROL, see test_tampered_mandant_segment_is_rejected().
        $this->assertNotNull(
            $service->parse($this->encode($parts['id'].'.'.$parts['signature'].'.'.$parts['mandantId'])),
            'control: the unaltered payload must verify — otherwise the rejections below prove nothing',
        );

        // A non-digit mandant segment is read as part of the (binary)
        // signature — the token is then re-checked as a legacy v1 token, whose
        // signed message differs, so it is rejected instead of silently
        // resolving without a tenant claim.
        $this->assertNull($service->parse($this->encode($parts['id'].'.'.$parts['signature'].'.abc')));
        $this->assertNull($service->parse($this->encode($parts['id'].'.'.$parts['signature'].'.0')));
        $this->assertNull($service->parse($this->encode($parts['id'].'.'.$parts['signature'].'.-1')));
        $this->getJson('/api/verify/'.$this->encode($parts['id'].'.'.$parts['signature'].'.abc'))->assertStatus(404);
    }

    public function test_verify_without_a_resolved_mandant_resolves_nothing(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $token = app(QrTokenService::class)->make($application);

        // Fail closed: a request that cannot be attributed to a tenant (only
        // reachable in console/test contexts — in production the middleware
        // answers 404 for an unknown host) resolves nothing.
        MandantContext::reset();

        $this->getJson('/api/verify/'.$token)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Invalid verification token.']);
    }

    public function test_is_valid_for_rejects_tokens_of_other_applications_and_mandants(): void
    {
        $service = app(QrTokenService::class);
        $applicationA = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $applicationB = $this->approvedApplication($this->mandantB, 'Max Muster');

        $tokenA = $service->make($applicationA);

        $this->assertTrue($service->isValidFor($applicationA, $tokenA));
        $this->assertFalse($service->isValidFor($applicationB, $tokenA));
        $this->assertFalse($service->isValidFor($applicationA, $this->legacyToken((int) $applicationA->id)));
        $this->assertFalse($service->isValidFor($applicationA, 'garbage'));
    }

    /* ---------------------------------------------------------------------
     | Legacy (v1) compatibility
     | ------------------------------------------------------------------- */

    public function test_legacy_v1_token_still_verifies_and_the_mandant_scope_isolates_it(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $legacy = $this->legacyToken((int) $application->id);
        $application->update(['qr_token' => $legacy]);

        $service = app(QrTokenService::class);
        $claims = $service->parse($legacy);

        // A v1 token authenticates the application id only …
        $this->assertNotNull($claims);
        $this->assertSame($application->id, $claims->applicationId);
        $this->assertNull($claims->mandantId);
        $this->assertFalse($claims->isTenantBound());
        // … so it cannot restrict the tenant itself: the mandant-scoped lookup
        // of VerifyController is the isolation boundary for legacy badges.
        $this->assertTrue($claims->matchesMandant((int) $this->mandantB->id));

        // Owning mandant: full payload, as before the format change.
        $this->getJson('/api/verify/'.$legacy)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.name', 'Jane Doe');

        // Foreign mandant: the mandant-scoped query finds nothing.
        MandantContext::set($this->mandantB);

        $this->getJson('/api/verify/'.$legacy)
            ->assertStatus(404)
            ->assertExactJson(['message' => 'Invalid verification token.']);
    }

    public function test_legacy_token_of_a_foreign_secret_is_rejected(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $forged = $this->legacyToken((int) $application->id, 'some-other-secret');

        $this->assertNull(app(QrTokenService::class)->parse($forged));
        $this->getJson('/api/verify/'.$forged)->assertStatus(404);
    }

    public function test_a_legacy_signature_ending_in_a_dot_and_digits_still_verifies(): void
    {
        // The ambiguous split: a raw HMAC may contain '.' bytes, so a v1 token
        // whose signature happens to end in a '.' followed by digits only is
        // indistinguishable from a v2 token by segment shape alone. The parser
        // used to read that digit run as a `mandantId` claim, fail the v2 check
        // and reject the token — ~0.05 % of issued ids (measured: 4 of 40 000),
        // so a real badge silently 404s.
        //
        // The key is derived so that the ambiguity holds for the application id
        // the ENGINE assigned (see LEGACY_FIXTURE_KEY) — the property under
        // test, not the number 1.
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');

        $key = $this->ambiguousLegacyKeyFor((int) $application->id);

        $this->assertNotNull(
            $key,
            'precondition: no key found under which this application id is ambiguous — the fixture search is broken',
        );

        config(['app.key' => $key, 'app.previous_keys' => []]);

        $signature = hash_hmac('sha256', (string) $application->id, $key, true);
        $lastDot = strrpos($signature, '.');

        $this->assertIsInt($lastDot, 'precondition: the fixture signature must contain a dot');
        $this->assertTrue(
            ctype_digit(substr($signature, $lastDot + 1)),
            'precondition: everything after the last dot must be digits, or the token is a well-formed v2 token',
        );

        $legacy = $this->encode($application->id.'.'.$signature);
        $application->update(['qr_token' => $legacy]);

        $claims = app(QrTokenService::class)->parse($legacy);

        $this->assertNotNull($claims, 'a legacy token must never be rejected for the shape of its signature');
        $this->assertSame((int) $application->id, $claims->applicationId);
        $this->assertNull($claims->mandantId, 'a v1 token carries no tenant claim');
        $this->assertFalse($claims->isTenantBound());

        // … and the mandant-scoped lookup of VerifyController still resolves it
        // for the owning mandant (and only for it).
        $this->getJson('/api/verify/'.$legacy)
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.name', 'Jane Doe');

        MandantContext::set($this->mandantB);
        $this->getJson('/api/verify/'.$legacy)->assertStatus(404);
    }

    /**
     * The companion of the test above, and the regression guard against a
     * return to a hardcoded id: the ambiguity belongs to the PAIR (application
     * id, key), not to any single id. That test needs a real row, so its id is
     * whatever the engine hands out (1 on SQLite, an ever-growing sequence value
     * on Postgres). This one pins the very same scenario for an id no engine
     * would ever assign on its own, without touching the database at all — it
     * fails on SQLite too if anyone re-introduces an id assumption.
     */
    public function test_the_ambiguous_legacy_fixture_holds_for_an_arbitrary_application_id(): void
    {
        $applicationId = 4711;

        $key = $this->ambiguousLegacyKeyFor($applicationId);

        $this->assertNotNull(
            $key,
            'precondition: no key found under which this application id is ambiguous — the fixture search is broken',
        );

        config(['app.key' => $key, 'app.previous_keys' => []]);

        $signature = hash_hmac('sha256', (string) $applicationId, $key, true);
        $lastDot = strrpos($signature, '.');

        $this->assertIsInt($lastDot, 'precondition: the fixture signature must contain a dot');
        $this->assertTrue(
            ctype_digit(substr($signature, $lastDot + 1)),
            'precondition: everything after the last dot must be digits, or the token is a well-formed v2 token',
        );

        $claims = app(QrTokenService::class)->parse($this->encode($applicationId.'.'.$signature));

        $this->assertNotNull($claims, 'a legacy token must never be rejected for the shape of its signature');
        $this->assertSame($applicationId, $claims->applicationId);
        $this->assertNull($claims->mandantId, 'a v1 token carries no tenant claim');
        $this->assertFalse($claims->isTenantBound());
    }

    public function test_the_legacy_fallback_never_turns_a_tampered_v2_token_into_a_v1_token(): void
    {
        // The parser now always retries the legacy check over the whole payload.
        // That must stay harmless: the legacy check is the full HMAC of the
        // application id under a known key, which a tampered payload cannot
        // produce — so a v2 token with a forged mandant segment is still
        // rejected outright rather than silently resolving WITHOUT a tenant
        // claim.
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $token = app(QrTokenService::class)->make($application);

        $parts = $this->splitV2Payload((string) base64_decode(strtr($token, '-_', '+/'), true));

        // CONTROL, see test_tampered_mandant_segment_is_rejected(): the original
        // must verify as a tenant-bound token, so the rejections below are about
        // the forged claim and not about a payload mangled on the way here.
        $this->assertTrue(app(QrTokenService::class)->isValidFor($application, $token), 'control: the unaltered token must verify — otherwise the rejections below prove nothing');

        foreach ([$this->mandantB->id.'0', '0', (string) ((int) $parts['mandantId'] + 1)] as $forgedMandant) {
            $forged = $this->encode($parts['id'].'.'.$parts['signature'].'.'.$forgedMandant);

            $this->assertNull(app(QrTokenService::class)->parse($forged), 'mandant segment: '.$forgedMandant);
            $this->assertFalse(app(QrTokenService::class)->isValidFor($application, $forged));
        }
    }

    public function test_make_upgrades_a_stored_legacy_token_to_v2(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $legacy = $this->legacyToken((int) $application->id);
        $application->update(['qr_token' => $legacy]);

        $token = app(QrTokenService::class)->make($application);

        $this->assertNotSame($legacy, $token);
        $this->assertSame($token, $application->fresh()->qr_token);

        $claims = app(QrTokenService::class)->parse($token);
        $this->assertNotNull($claims);
        $this->assertTrue($claims->isTenantBound());
        $this->assertSame($this->mandantA->id, $claims->mandantId);
    }

    /* ---------------------------------------------------------------------
     | Key rotation
     | ------------------------------------------------------------------- */

    public function test_stored_token_stays_valid_after_a_key_rotation_with_previous_keys(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $oldKey = (string) config('app.key');
        $token = app(QrTokenService::class)->make($application);

        // `php artisan key:generate` + the old key in APP_PREVIOUS_KEYS.
        config(['app.key' => self::NEW_KEY, 'app.previous_keys' => [$oldKey]]);

        $claims = app(QrTokenService::class)->parse($token);
        $this->assertNotNull($claims, 'a token signed with a previous key must still verify');
        $this->assertSame($application->id, $claims->applicationId);
        $this->assertSame($this->mandantA->id, $claims->mandantId);

        $this->getJson('/api/verify/'.$token)
            ->assertOk()
            ->assertJsonPath('data.name', 'Jane Doe');

        // Idempotency survives the rotation: a still-valid token is NOT re-minted.
        $this->assertSame($token, app(QrTokenService::class)->make($application->fresh()));
        $this->assertSame($token, $application->fresh()->qr_token);
    }

    public function test_make_remints_a_stored_token_that_no_longer_verifies(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $oldToken = app(QrTokenService::class)->make($application);

        // A rotation WITHOUT APP_PREVIOUS_KEYS used to kill every issued badge:
        // the stored token is dead and `/api/verify` answers 404 forever (the
        // old `make()` returned the stored value verbatim and the backfill
        // skipped non-NULL rows, so nothing could repair it).
        config(['app.key' => self::NEW_KEY, 'app.previous_keys' => []]);

        $this->assertNull(app(QrTokenService::class)->parse($oldToken));
        $this->getJson('/api/verify/'.$oldToken)->assertStatus(404);

        // The first write path that touches the row heals it.
        $newToken = app(QrTokenService::class)->make($application);

        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame($newToken, $application->fresh()->qr_token);
        $this->assertNotNull(app(QrTokenService::class)->parse($newToken));

        $this->getJson('/api/verify/'.$newToken)
            ->assertOk()
            ->assertJsonPath('data.name', 'Jane Doe');
    }

    public function test_previous_keys_are_not_used_for_minting(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        config(['app.key' => self::NEW_KEY, 'app.previous_keys' => ['base64:b2xkLWtleS0xMjM0NTY3ODkwMTIzNDU2Nzg5MA==']]);

        $token = app(QrTokenService::class)->make($application);
        $parts = $this->splitV2Payload((string) base64_decode(strtr($token, '-_', '+/'), true));

        $this->assertSame(
            hash_hmac('sha256', 'v2:'.$application->id.':'.$this->mandantA->id, self::NEW_KEY, true),
            $parts['signature'],
            'tokens are always signed with the CURRENT key, so a rotation heals the column',
        );
    }

    /**
     * F5: `APP_PREVIOUS_KEYS` had two consumers that disagreed on key
     * normalization.
     *
     * `QrTokenService` HMAC'd the CONFIGURED STRING verbatim, while the
     * encrypter runs `parseKey()` — it strips the `base64:` prefix and
     * base64-decodes. `php artisan key:generate` prints the `base64:` prefix, so
     * an operator who pastes only the raw value (or pastes the value the encrypter
     * itself accepts) gets: encryption keeps working, but every badge signed with
     * that key 404s — silently, with no error anywhere.
     *
     * Pinned here: a previous key listed WITHOUT the `base64:` prefix must still
     * verify a token that was signed with the prefixed form, and `make()` must
     * not consider such a token stale and re-mint it.
     */
    public function test_a_previous_key_without_the_base64_prefix_still_verifies(): void
    {
        $rawKey = '0123456789abcdef0123456789abcdef';
        $prefixed = 'base64:'.base64_encode($rawKey);

        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        config(['app.key' => $prefixed, 'app.previous_keys' => []]);

        // Signed with the prefixed key, exactly as `key:generate` would.
        $token = app(QrTokenService::class)->make($application);
        $this->assertNotNull(app(QrTokenService::class)->parse($token));

        // Rotation, and the operator pastes the previous key WITHOUT the prefix.
        config([
            'app.key' => self::NEW_KEY,
            'app.previous_keys' => [base64_encode($rawKey)],
        ]);

        $claims = app(QrTokenService::class)->parse($token);

        $this->assertNotNull($claims, 'a prefix-less previous key must verify the badges it signed');
        $this->assertSame($application->id, $claims->applicationId);
        $this->assertSame($this->mandantA->id, $claims->mandantId);

        $this->getJson('/api/verify/'.$token)
            ->assertOk()
            ->assertJsonPath('data.name', 'Jane Doe');

        // …and the stored token is still considered current, so the backfill and
        // the write paths do not churn the column for nothing.
        $this->assertSame($token, app(QrTokenService::class)->make($application->fresh()));
    }

    /**
     * The counterpart: the PREFIXED spelling of a previous key keeps working —
     * the normalization adds an alias, it must not replace the literal key
     * material that every already-issued badge was signed with.
     */
    public function test_a_previous_key_with_the_base64_prefix_still_verifies(): void
    {
        $rawKey = 'abcdef0123456789abcdef0123456789';
        $prefixed = 'base64:'.base64_encode($rawKey);

        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        config(['app.key' => $prefixed, 'app.previous_keys' => []]);

        $token = app(QrTokenService::class)->make($application);

        config(['app.key' => self::NEW_KEY, 'app.previous_keys' => [$prefixed]]);

        $this->assertNotNull(app(QrTokenService::class)->parse($token));
        $this->assertSame($token, app(QrTokenService::class)->make($application->fresh()));
    }

    /**
     * The normalized alias is exactly the encrypter's key material — the same
     * secret, never a weaker or wider acceptance. A token signed with the RAW
     * bytes is recognized, and a token signed with an unrelated secret is still
     * rejected.
     */
    public function test_the_previous_key_normalization_does_not_widen_acceptance(): void
    {
        $rawKey = 'fedcba9876543210fedcba9876543210';
        $prefixed = 'base64:'.base64_encode($rawKey);

        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');

        config(['app.key' => self::NEW_KEY, 'app.previous_keys' => [$prefixed]]);

        $rawSignature = hash_hmac('sha256', 'v2:'.$application->id.':'.$this->mandantA->id, $rawKey, true);
        $rawSigned = $this->encode($application->id.'.'.$rawSignature.'.'.$this->mandantA->id);

        $claims = app(QrTokenService::class)->parse($rawSigned);
        $this->assertNotNull($claims, 'the raw key material is the same secret the encrypter uses');
        $this->assertTrue($claims->isTenantBound());

        $foreign = $this->encode(
            $application->id
            .'.'.hash_hmac('sha256', 'v2:'.$application->id.':'.$this->mandantA->id, 'some-other-secret', true)
            .'.'.$this->mandantA->id,
        );

        $this->assertNull(app(QrTokenService::class)->parse($foreign));
        $this->getJson('/api/verify/'.$foreign)->assertStatus(404);
    }

    /**
     * A previous key that is not valid base64 (a legacy raw passphrase) must
     * stay usable verbatim — the normalization is additive and falls back to the
     * literal string when the value cannot be decoded.
     */
    public function test_a_non_base64_previous_key_stays_usable_verbatim(): void
    {
        $legacy = 'wp1-legacy-raw-passphrase-not-base64-9911';

        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        config(['app.key' => $legacy, 'app.previous_keys' => []]);

        $token = app(QrTokenService::class)->make($application);

        config(['app.key' => self::NEW_KEY, 'app.previous_keys' => [$legacy]]);

        $this->assertNotNull(app(QrTokenService::class)->parse($token));
        $this->assertSame($token, app(QrTokenService::class)->make($application->fresh()));
    }

    /* ---------------------------------------------------------------------
     | Backfill command
     | ------------------------------------------------------------------- */

    public function test_backfill_upgrades_legacy_and_null_tokens_to_v2_and_is_idempotent(): void
    {
        $missing = $this->approvedApplication($this->mandantA, 'Ohne Token');
        $legacy = $this->approvedApplication($this->mandantA, 'Legacy');
        $legacy->update(['qr_token' => $this->legacyToken((int) $legacy->id)]);
        $current = $this->approvedApplication($this->mandantA, 'Aktuell');
        $currentToken = app(QrTokenService::class)->make($current);
        $requested = $this->approvedApplication($this->mandantA, 'Beantragt', 'requested');
        $requested->update(['qr_token' => $this->legacyToken((int) $requested->id)]);

        $this->assertNull($missing->fresh()->qr_token);

        $this->assertSame(0, Artisan::call('accreditation:backfill-qr-tokens'));

        $service = app(QrTokenService::class);
        $missingClaims = $service->parse((string) $missing->fresh()->qr_token);
        $legacyClaims = $service->parse((string) $legacy->fresh()->qr_token);

        $this->assertNotNull($missingClaims);
        $this->assertTrue($missingClaims->isTenantBound());
        $this->assertNotNull($legacyClaims);
        $this->assertTrue($legacyClaims->isTenantBound(), 'a legacy v1 token must be upgraded to v2');
        $this->assertSame($currentToken, $current->fresh()->qr_token, 'an up-to-date token is not rewritten');

        // Non-approved rows are not part of the command's scope.
        $this->assertSame($this->legacyToken((int) $requested->id), $requested->fresh()->qr_token);

        // Idempotent: a second run changes nothing at all.
        $before = $this->tokenSnapshot();
        $this->assertSame(0, Artisan::call('accreditation:backfill-qr-tokens'));
        $this->assertSame($before, $this->tokenSnapshot());
    }

    public function test_backfill_heals_tokens_of_a_rotated_key(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $deadToken = app(QrTokenService::class)->make($application);

        config(['app.key' => self::NEW_KEY, 'app.previous_keys' => []]);

        $this->assertSame(0, Artisan::call('accreditation:backfill-qr-tokens'));

        $this->assertNotSame($deadToken, $application->fresh()->qr_token);
        $this->assertNotNull(app(QrTokenService::class)->parse((string) $application->fresh()->qr_token));
    }

    /* ---------------------------------------------------------------------
     | Read paths
     | ------------------------------------------------------------------- */

    public function test_admin_resource_serves_a_valid_v2_url_for_a_legacy_row_without_writing(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $legacy = $this->legacyToken((int) $application->id);
        $application->update(['qr_token' => $legacy]);

        $entry = collect($this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $application->id);

        $this->assertIsArray($entry);
        $served = substr((string) $entry['qr_url'], strlen('/verify/'));

        $this->assertNotSame($legacy, $served, 'the admin view must not hand out an outdated token');
        $this->assertNotNull(app(QrTokenService::class)->parse($served));

        // Read path stays read-only: the column is repaired by the write paths
        // and the backfill command, never by serialization.
        $this->assertSame($legacy, $application->fresh()->qr_token);
    }

    public function test_admin_resource_prefers_a_valid_stored_token(): void
    {
        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $token = app(QrTokenService::class)->make($application);

        $entry = collect($this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/applications')
            ->assertOk()
            ->json('data'))
            ->firstWhere('id', $application->id);

        $this->assertIsArray($entry);
        $this->assertSame('/verify/'.$token, $entry['qr_url']);
    }

    /* ---------------------------------------------------------------------
     | F2 — the verify URL must be attributable to the exporting mandant
     | ------------------------------------------------------------------- */

    /**
     * F2: `BadgeRenderService::host()` falls back to the host of
     * `config('app.url')` when the mandant has no domain. In production that
     * host is the PRIMARY mandant's own host, so a domain-less mandant's badges
     * embed a verify URL pointing at a FOREIGN host. A v2 token signs the
     * owning mandant id and `VerifyController` requires `matchesMandant()`, so
     * every single scan answers 404 — and no backfill repairs it, because the
     * token is fine; the URL around it is wrong.
     *
     * The export therefore refuses (422) instead of printing badges that can
     * never verify.
     */
    public function test_badge_export_refuses_a_mandant_whose_verify_host_belongs_to_another_mandant(): void
    {
        $primary = Mandant::factory()->create([
            'slug' => 'verband-primary',
            'name' => 'Primaerer Verband',
            'is_primary' => true,
        ]);
        $this->writeDomain($primary, 'akademie.test');

        // The deployment shape: APP_URL is the primary mandant's own host.
        config(['app.url' => 'https://akademie.test']);

        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $this->createDefaultBadgeTemplate();

        $this->assertNull(
            app(MediaHostResolver::class)->hostFor($this->mandantA),
            'precondition: the exporting mandant has no domain of its own',
        );

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$application->accreditation_id.'/badges/export', ['format' => 'csv'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mandant hat keine Domain — QR-Codes können nicht generiert werden.');
    }

    /**
     * The counterpart: a mandant WITH a domain exports normally and the embedded
     * verify URL carries its own host — the guard must not fire on the healthy
     * path.
     */
    public function test_badge_export_embeds_the_own_domain_of_a_mandant_that_has_one(): void
    {
        $this->writeDomain($this->mandantA, 'verband-a.test');

        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $this->createDefaultBadgeTemplate();

        $response = $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$application->accreditation_id.'/badges/export', ['format' => 'csv'])
            ->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString($this->expectedVerifyUrlPrefix('verband-a.test'), $csv);
        $this->assertStringNotContainsString('akademie.test', $csv);
    }

    /**
     * F2-Residual: the export guard is UNCONDITIONAL. A mandant without a
     * domain cannot export, even when the `config('app.url')` verify host is
     * routed to nobody (the local-dev shape, `APP_URL=http://localhost`).
     *
     * The earlier narrowing explicitly allowed exactly this shape (see the
     * former docblock: "refusing it would break single-box development"). That
     * left a real hole: the badge's verify URL names the fallback host, and the
     * moment that host is routed to a mandant the already-printed badges 404 —
     * the token is bound to THIS mandant, the URL around it is not. Single-box
     * development now has to add a `mandant_domains` row, exactly like every
     * other mandant that mints verifiable badges.
     */
    public function test_badge_export_refuses_a_domain_less_mandant_even_while_its_verify_host_is_unowned(): void
    {
        config(['app.url' => 'http://localhost']);

        $application = $this->approvedApplication($this->mandantA, 'Jane Doe');
        $this->createDefaultBadgeTemplate();

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/accreditations/'.$application->accreditation_id.'/badges/export', ['format' => 'csv'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Mandant hat keine Domain — QR-Codes können nicht generiert werden.');
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function writeDomain(Mandant $mandant, string $hostname): void
    {
        $mandant->domains()->create(['hostname' => $hostname]);
    }

    private function createDefaultBadgeTemplate(): void
    {
        BadgeTemplate::create([
            'mandant_id' => $this->mandantA->id,
            'name' => 'Presseausweis',
            'layout' => [
                ['field' => 'name', 'x' => 10, 'y' => 10, 'w' => 80, 'h' => 10, 'size' => 14, 'align' => 'left'],
            ],
            'is_default' => true,
        ]);
    }

    private function approvedApplication(Mandant $mandant, string $name, string $status = 'approved'): Application
    {
        $category = $mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        $accreditation = $mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
        ]);

        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => User::factory()->create(['name' => $name])->id,
            'status' => $status,
            'priority' => false,
        ]);
    }

    private function storePortrait(?User $user): UserMedia
    {
        $media = UserMedia::create([
            'user_id' => $user->id,
            'type' => 'portrait',
            'path' => 'user-media/verband-a/'.$user->id.'/portrait/portrait-'.(++self::$mediaCount).'.png',
            'mime' => 'image/png',
            'size' => 21,
            'original_name' => 'portrait.png',
        ]);

        Storage::disk('private')->put($media->path, 'fake-portrait-bytes');

        return $media;
    }

    /**
     * A legacy (v1) token: `base64url(id . hmac(secret, id))`.
     */
    private function legacyToken(int $applicationId, ?string $key = null): string
    {
        $key ??= (string) config('app.key');

        return $this->encode($applicationId.'.'.hash_hmac('sha256', (string) $applicationId, $key, true));
    }

    private function encode(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * Split a decoded v2 payload into its three claims — the ONE place in this
     * suite that knows how to read one, used by every test that inspects the
     * shape of a token. It mirrors `QrTokenService::parse()`: `strpos()` for the
     * application id (`QrTokenService.php:163`) and `strrpos()` for the mandant
     * claim (`:193`) — from the OUTSIDE in.
     *
     * Why outside-in, and why `explode('.', $decoded)` is the wrong tool here:
     * the signature is a RAW HMAC (`hash_hmac('sha256', …, $key, true)`, 32
     * binary bytes) and can therefore contain a '.' byte (0x2E). Measured on the
     * signing key of `phpunit.xml:44`: **11.51 %** of the application ids 1..20000
     * produce a v2 signature with such a byte (11.93 % for the v1 message under
     * the dev key) — so roughly one id in eight makes `explode()` return four or
     * more segments. Which ids those are depends on the PAIR (application id,
     * mandant id): for mandant 1 the offenders within ids 1..40 are 13, 22, 28, 30
     * and 40; for mandant 8 they are 35 and others; see tokenShapePairs() for
     * the pairs this suite pins. The segment count of a token is therefore an
     * accident of the id, never an invariant of the format, and the id comes from
     * the DB sequence — engine-dependent (SQLite restarts per test database, a
     * Postgres sequence keeps counting across rolled-back tests), which is exactly
     * how `assertCount(3, …)` came to be red in CI on Postgres while the product
     * was correct.
     *
     * The outside-in split is nevertheless unambiguous: the application id
     * precedes the FIRST separator and is all digits, the mandant claim follows
     * the LAST separator and is all digits, so neither claim can hide a dot and
     * the bytes in between are exactly the signature.
     *
     * Its preconditions are asserted HERE rather than in each caller: a payload
     * that is not well-formed v2 fails loudly at the split instead of silently
     * handing out nonsense parts to a "forged token" assertion.
     *
     * @return array{
     *     id: string,
     *     signature: string,
     *     mandantId: string,
     *     signatureHasDot: bool,
     *     naiveSegmentCount: int,
     * }
     */
    private function splitV2Payload(string $decoded): array
    {
        $firstSeparator = strpos($decoded, '.');
        $lastSeparator = strrpos($decoded, '.');

        $this->assertIsInt($firstSeparator, 'premise: a v2 payload separates the id from the signature — got '.strlen($decoded).' raw bytes with no "." at all');
        $this->assertGreaterThan($firstSeparator, $lastSeparator, 'premise: a v2 payload carries the signature BETWEEN two separators');

        $id = substr($decoded, 0, $firstSeparator);
        $signature = substr($decoded, $firstSeparator + 1, $lastSeparator - $firstSeparator - 1);
        $mandantId = substr($decoded, $lastSeparator + 1);

        $this->assertNotSame('', $id, 'premise: the application id claim must not be empty');
        $this->assertTrue(ctype_digit($id), 'premise: the application id claim is all digits and so cannot hide a separator — got '.var_export($id, true));
        $this->assertNotSame('', $signature, 'premise: the signature between the claims must not be empty');
        $this->assertTrue(ctype_digit($mandantId), 'premise: the mandant claim is all digits and so cannot hide a separator — got '.var_export($mandantId, true));

        return [
            'id' => $id,
            'signature' => $signature,
            'mandantId' => $mandantId,
            'signatureHasDot' => str_contains($signature, '.'),
            'naiveSegmentCount' => substr_count($decoded, '.') + 1,
        ];
    }

    /**
     * An UNSAVED application with a caller-chosen id and mandant claim, for the
     * cases that must exercise a SPECIFIC id instead of whatever the database
     * sequence hands out. `QrTokenService::token()` is pure and reads the
     * mandant claim from the preloaded relation (`mandantIdOf()`), so no row is
     * needed and nothing is written.
     */
    private function applicationWithId(int $applicationId, int $mandantId): Application
    {
        $accreditation = new Accreditation;
        $accreditation->forceFill(['mandant_id' => $mandantId]);

        $application = new Application;
        $application->forceFill([
            'id' => $applicationId,
            'accreditation_id' => 0,
            'status' => 'approved',
            'priority' => false,
        ]);
        $application->setRelation('accreditation', $accreditation);

        return $application;
    }

    /**
     * The first id (1..5000) whose signature under `$signer` contains a '.'
     * byte — the binary-signature edge case of the token parser.
     */
    private function firstIdWithDottedSignature(callable $signer): int
    {
        for ($id = 1; $id <= 5000; $id++) {
            if (str_contains($signer($id), '.')) {
                return $id;
            }
        }

        return 0;
    }

    /**
     * The first key of the form `<LEGACY_FIXTURE_KEY>` / `<LEGACY_FIXTURE_KEY>-<n>`
     * under which the legacy signature of `$applicationId` ends in a '.' byte
     * followed by digits only — i.e. the first key that makes a v1 token of that
     * id indistinguishable from a v2 token by segment shape alone.
     *
     * Null if none of the `LEGACY_FIXTURE_KEY_SEARCH_LIMIT` candidates is, so
     * the caller can fail loudly instead of testing a fixture that is not
     * ambiguous after all.
     *
     * The two conditions checked here are the parser's own (`QrTokenService::
     * parse()`: a trailing all-digit segment is read as a mandant claim), and
     * they are the definition of "ambiguous" for this suite — not an
     * approximation of it.
     */
    private function ambiguousLegacyKeyFor(int $applicationId): ?string
    {
        for ($n = 0; $n < self::LEGACY_FIXTURE_KEY_SEARCH_LIMIT; $n++) {
            $key = $n === 0 ? self::LEGACY_FIXTURE_KEY : self::LEGACY_FIXTURE_KEY.'-'.$n;

            $signature = hash_hmac('sha256', (string) $applicationId, $key, true);
            $lastDot = strrpos($signature, '.');

            if ($lastDot !== false && ctype_digit(substr($signature, $lastDot + 1))) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<int, string|null> application id => stored token
     */
    private function tokenSnapshot(): array
    {
        return Application::query()->orderBy('id')->pluck('qr_token', 'id')->all();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->firstOrFail();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $user;
    }
}
