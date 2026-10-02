<?php

namespace Tests\Feature;

use App\Http\Resources\AdminApplicationResource;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\User;
use App\Services\AllocationService;
use App\Services\QrTokenService;
use App\Support\MandantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The bulk approval must UPGRADE a stored legacy v1 QR token, not skip it.
 *
 * `QrTokenService::make()` is self-healing — it re-mints a stored token that is
 * not a valid, tenant-bound v2 token of the application (see the service's
 * "Keys and rotation" contract). Every write path relies on that: single
 * approval (`AllocationService::approveApplication()`), resend, wallet pass,
 * badge render and `accreditation:backfill-qr-tokens`.
 *
 * `AllocationService::issueQrTokens()` used to add `->whereNull('qr_token')` on
 * top, which made the bulk path the single write path that could NOT upgrade: a
 * row that already held a v1 token was filtered out and kept it. The
 * consequences were
 *   - a silently degraded tenant binding (the badge only verified through the
 *     legacy, tenant-unbound branch of `parse()`), and
 *   - a display/column divergence, because `AdminApplicationResource::
 *     verifyToken()` falls back to a freshly computed v2 token when the stored
 *     one is not tenant-bound, so the admin UI served a v2 URL while the column
 *     still held v1.
 *
 * Not a cross-tenant leak: `VerifyController` fails closed and resolves the
 * application mandant-scoped for a v1 token (`QrTokenV2Test` pins that).
 */
class AllocationQrTokenUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private static int $categorySeq = 0;

    private AllocationService $allocation;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.previous_keys' => []]);

        // Position 45: the mail is queued now; fake it so the sync job does not
        // dial a real relay (this class asserts token upgrades, not delivery).
        Mail::fake();

        $this->allocation = app(AllocationService::class);
        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();

        parent::tearDown();
    }

    public function test_a_bulk_approval_upgrades_a_stored_legacy_v1_token_to_v2(): void
    {
        $accreditation = $this->accreditationWithApplicants(2);

        // Both applicants already carry a LEGACY v1 token — the exact state the
        // `whereNull('qr_token')` filter used to skip.
        $legacy = [];
        foreach (Application::query()->where('accreditation_id', $accreditation->id)->get() as $application) {
            $legacy[(int) $application->id] = $this->legacyToken((int) $application->id);
            $application->update(['qr_token' => $legacy[(int) $application->id]]);
        }

        $this->assertCount(2, $legacy);
        foreach ($legacy as $token) {
            $claims = app(QrTokenService::class)->parse($token);
            $this->assertNotNull($claims);
            $this->assertFalse($claims->isTenantBound(), 'precondition: the fixture is a v1 token');
        }

        $this->allocation->approveAllEligible($accreditation);

        $service = app(QrTokenService::class);
        $upgraded = Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('status', 'approved')
            ->get();

        $this->assertCount(2, $upgraded);

        foreach ($upgraded as $application) {
            $stored = (string) $application->fresh()->qr_token;

            $this->assertNotSame(
                $legacy[(int) $application->id],
                $stored,
                'the bulk approval left the legacy v1 token in place',
            );

            $claims = $service->parse($stored);
            $this->assertNotNull($claims, 'the upgraded token must verify');
            $this->assertTrue(
                $claims->isTenantBound(),
                'the upgraded token must carry the mandant claim, otherwise the badge is tenant-unbound',
            );
            $this->assertSame((int) $this->mandant->id, $claims->mandantId);
            $this->assertSame((int) $application->id, $claims->applicationId);
            $this->assertTrue($service->isValidFor($application, $stored));
        }
    }

    /**
     * The user-visible consequence: the admin API's `qr_url` must be built from
     * the SAME token that is stored in the column. Before the fix the resource
     * fell back to a freshly computed v2 token while the row kept v1.
     */
    public function test_the_admin_qr_url_and_the_stored_column_no_longer_diverge(): void
    {
        $accreditation = $this->accreditationWithApplicants(1);
        $application = Application::query()->where('accreditation_id', $accreditation->id)->firstOrFail();
        $application->update(['qr_token' => $this->legacyToken((int) $application->id)]);

        $this->allocation->approveAllEligible($accreditation);

        $approved = Application::query()
            ->where('accreditation_id', $accreditation->id)
            ->where('status', 'approved')
            ->firstOrFail()
            ->fresh();

        $qrUrl = (new AdminApplicationResource($approved))->toArray(Request::create('/'))['qr_url'];

        $this->assertSame('/verify/'.$approved->qr_token, $qrUrl);
    }

    /**
     * Dropping the filter must not reintroduce a write amplification: a row that
     * already holds a valid, tenant-bound v2 token is returned early by
     * `make()` after one HMAC verification and must not be rewritten.
     */
    public function test_a_bulk_approval_does_not_rewrite_already_valid_v2_tokens(): void
    {
        $accreditation = $this->accreditationWithApplicants(3);

        // Mint the v2 tokens the way the single-approval write path would.
        foreach (Application::query()->where('accreditation_id', $accreditation->id)->get() as $application) {
            app(QrTokenService::class)->make($application);
        }

        $queries = $this->recordQueries(fn () => $this->allocation->approveAllEligible($accreditation));

        $qrWrites = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_starts_with($query['sql'], 'update')
                && str_contains($query['sql'], 'qr_token'),
        ));

        $this->assertSame(
            [],
            $qrWrites,
            'a valid v2 token must stay untouched: '.implode(PHP_EOL, array_column($qrWrites, 'sql')),
        );
    }

    /**
     * The eager load that keeps the upgrade cheap: the v2 token needs the
     * mandant id of the accreditation, so it must never be lazy-loaded
     * row by row. Same guarantee as `AllocationQrTokenQueryTest`, asserted
     * again for the upgraded (previously skipped) rows.
     *
     * R-D4 adjusted the expectation from "zero" to "exactly one": the run now
     * opens with the row-locked read of the quota row
     * (`Accreditation::query()->lockForUpdate()->findOrFail()`), which is one
     * query per run by construction. A lazy load per approved row would add
     * one more each, so the count still catches the N+1 — it is now a *stricter*
     * statement than before, not a looser one.
     */
    public function test_upgrading_does_not_lazy_load_the_accreditation_row_by_row(): void
    {
        $accreditation = $this->accreditationWithApplicants(2);
        foreach (Application::query()->where('accreditation_id', $accreditation->id)->get() as $application) {
            $application->update(['qr_token' => $this->legacyToken((int) $application->id)]);
        }

        $queries = $this->recordQueries(fn () => $this->allocation->approveAllEligible($accreditation));

        $rowByRow = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['sql'], 'from "accreditations"')
                && str_contains($query['sql'], '"id" = ?'),
        ));

        $this->assertCount(
            1,
            $rowByRow,
            'only the row-locked quota read, never one per approved row: '.implode(PHP_EOL, array_column($rowByRow, 'sql')),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function accreditationWithApplicants(int $count): Accreditation
    {
        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        $accreditation = $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => $count,
        ]);

        for ($i = 0; $i < $count; $i++) {
            Application::create([
                'accreditation_id' => $accreditation->id,
                'user_id' => User::factory()->create()->id,
                'status' => 'requested',
                'priority' => false,
            ]);
        }

        return $accreditation;
    }

    /**
     * A legacy v1 token: `base64url(applicationId . '.' . hmac(id))` — no version
     * marker, no mandant claim.
     */
    private function legacyToken(int $applicationId): string
    {
        $key = (string) config('app.key');
        $payload = $applicationId.'.'.hash_hmac('sha256', (string) $applicationId, $key, true);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /**
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function recordQueries(Closure $callback): array
    {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $callback();

        return $queries;
    }
}
