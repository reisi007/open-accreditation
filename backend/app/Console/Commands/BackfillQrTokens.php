<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\QrTokenService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * P4 follow-up (F2, low): one-time backfill of the `qr_token` column for
 * approved applications that predate the P4 token issuance at approval time.
 *
 * The admin application resource computes the token deterministically on READ
 * (`QrTokenService::token`) and never writes it back, so legacy approved rows
 * keep working without a DB write during serialization. This command
 * materialises the token on those rows once, so they match the post-P4
 * invariant ("every approved application has a qr_token") and admin views stay
 * consistent with a stored value.
 *
 * Idempotent: `make()` only writes when the column is NULL, so re-runs (or a
 * scheduled repeat) are harmless — already-populated rows are skipped.
 */
class BackfillQrTokens extends Command
{
    protected $signature = 'accreditation:backfill-qr-tokens';

    protected $description = 'Backfill qr_token for approved applications that have none (one-time, idempotent)';

    public function handle(QrTokenService $qrTokenService): int
    {
        $count = 0;

        Application::query()
            ->where('status', 'approved')
            ->whereNull('qr_token')
            ->orderBy('id')
            ->chunkById(200, function (Collection $applications) use ($qrTokenService, &$count): void {
                foreach ($applications as $application) {
                    $qrTokenService->make($application);
                    $count++;
                }
            });

        $this->info("Backfilled qr_token for {$count} approved application(s).");

        return self::SUCCESS;
    }
}
