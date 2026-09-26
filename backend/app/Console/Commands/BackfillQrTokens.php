<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\QrTokenService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * P4 follow-up (F2, low) + R-D3: the `qr_token` column of every approved
 * application is brought into the current (tenant-bound v2) format.
 *
 * The command covers three cases in one idempotent pass, all delegated to
 * `QrTokenService::make()`:
 *
 * 1. a NULL column — an approved row that predates the token issuance at
 *    approval time,
 * 2. a legacy v1 token — the pre-R-D3 format carried the application id only;
 *    it stays verifiable but is not tenant-bound, so it is upgraded,
 * 3. a token that no longer verifies against any known key — e.g. after an
 *    `APP_KEY` rotation that was performed without `APP_PREVIOUS_KEYS`.
 *
 * Idempotent by construction: `make()` re-mints only when the stored token is
 * missing or unusable, so re-runs (or a scheduled repeat) are no-ops. Chunked
 * with `chunkById` so a large accreditation set does not materialise at once
 * (the chunk's `accreditation` is eager-loaded — one query per chunk, not one
 * per application).
 *
 * Only `approved` rows are touched: a token is issued at approval time, and a
 * revoked (denied/blacklisted) row keeps the token it had so the badge still
 * verifies as revoked. Such a row is re-minted when it is approved again (or by
 * a manual run after the status is corrected).
 */
class BackfillQrTokens extends Command
{
    /** Applications per `chunkById` batch (rows, each with a portrait path). */
    private const CHUNK_SIZE = 200;

    protected $signature = 'accreditation:backfill-qr-tokens';

    protected $description = 'Backfill/refresh qr_token for approved applications (NULL, legacy v1, or unverifiable after key rotation)';

    public function handle(QrTokenService $qrTokenService): int
    {
        $scanned = 0;
        $written = 0;

        Application::query()
            ->where('status', 'approved')
            ->with('accreditation:id,mandant_id')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $applications) use ($qrTokenService, &$scanned, &$written): void {
                foreach ($applications as $application) {
                    $before = $application->qr_token;
                    $after = $qrTokenService->make($application);

                    $scanned++;

                    if ($after !== $before) {
                        $written++;
                    }
                }
            });

        $this->info(sprintf(
            'qr_token up to date for %d approved application(s) — %d written, %d already current.',
            $scanned,
            $written,
            $scanned - $written,
        ));

        return self::SUCCESS;
    }
}
