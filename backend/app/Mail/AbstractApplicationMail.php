<?php

namespace App\Mail;

use App\Models\Application;
use App\Services\WalletPassService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared plumbing for the P5 applicant notifications (approval, denial,
 * deadline reminder, pass). Every mailable targets the applicant of an
 * `Application`:
 *
 * - the recipient is derived from `application.user` (set via `to()`),
 * - the accreditation context (category/event/team/mandant domain) is eager-
 *   loaded once so the views and the verify-link builder never trigger
 *   unexpected lazy loads,
 * - the view data (user/category/event/team labels) is built in one place.
 *
 * German only for now (like `ActivationMail`); i18n of the mail templates is
 * a documented follow-up.
 */
abstract class AbstractApplicationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The wallet-pass payloads that were actually attached, mirrored for
     * inspection (`Mail::fake()` never invokes `attachments()`, so the tests
     * read this after calling it).
     *
     * @var list<array{name: string, mime: string, data: string}>
     */
    public array $walletAttachments = [];

    /**
     * Load the accreditation context, bind the applicant as recipient and
     * return the application (fully prepared for the view).
     */
    protected function prepare(Application $application): Application
    {
        $application->loadMissing([
            'user:id,email,name',
            'accreditation.category',
            'accreditation.event',
            'accreditation.team',
            'accreditation.mandant.domains',
        ]);

        if ($application->user !== null && $application->user->email !== null) {
            $this->to($application->user->email, $application->user->name);
        }

        return $application;
    }

    /**
     * The shared view data of every applicant notification.
     *
     * @return array<string, string|null>
     */
    protected function viewData(Application $application): array
    {
        return [
            'userName' => $application->user?->name ?? '',
            'categoryName' => $application->accreditation?->category?->name ?? '',
            'eventTitle' => $application->accreditation?->event?->title,
            'teamName' => $application->accreditation?->team?->name,
        ];
    }

    /**
     * P6: the wallet passes as mail attachments — the Apple `.pkpass` and the
     * Google payload, named and typed exactly like the download endpoint
     * (`WalletPassService::appleFilename()` / its `*_CONTENT_TYPE` constants are
     * the single contract for both).
     *
     * ## Fail-safe, by design
     *
     * The approval mail is the notification; the pass is a convenience. A pass
     * that cannot be built must never take the mail down, so each format is
     * built in its own `try`: on failure the format is logged via `Log::warning`
     * and SKIPPED, never rethrown. Since Position 45 `MandantMailerService::deliver()`
     * lets transport errors through (the queue retries and finally dead-letters
     * them), a throwing pass builder would burn the whole delivery budget and
     * lose the notification as well.
     *
     * ## Missing credentials are NOT a failure
     *
     * Without certificates/keys `WalletPassService` degrades deliberately: an
     * UNSIGNED `.pkpass` (no `signature`) and a preview `EventTicketObject`
     * JSON. Those degraded files ARE attached — that is the point of the
     * degradation, and the mail stays a usable pass/reference.
     *
     * ## Only approved applications carry a pass
     *
     * The guard below reads `$application->status` off the in-memory model, so
     * it stops an attachment for a row that was **never approved** (a mis-routed
     * caller or a stale instance) — it is not race protection. Race safety comes
     * from the caller: the bulk path re-reads committed state through the
     * `->where('status', 'approved')` query in
     * `AllocationService::dispatchApprovedMails`, while `approveApplication`
     * mirrors its guarded write onto the same instance only in memory
     * (`guardedStatusWrite`). A revocation landing between that commit and this
     * send is therefore not caught here — the Verify endpoint is the last
     * barrier: it refuses anything whose live row is no longer `approved`
     * (`VerifyResource` and `VerifyController::photo`).
     *
     * @return list<Attachment>
     */
    protected function buildWalletAttachments(Application $application): array
    {
        $this->walletAttachments = [];

        if ($application->status !== 'approved') {
            return [];
        }

        $wallet = app(WalletPassService::class);

        /** @var list<array{name: string, mime: string, build: callable(): string}> $formats */
        $formats = [
            [
                'name' => $wallet->appleFilename($application),
                'mime' => WalletPassService::APPLE_CONTENT_TYPE,
                'build' => fn (): string => $wallet->buildApplePass($application, 'main'),
            ],
            [
                'name' => $wallet->googleFilename($application),
                'mime' => WalletPassService::GOOGLE_CONTENT_TYPE,
                'build' => fn (): string => $wallet->buildGooglePass($application, 'main'),
            ],
        ];

        $attachments = [];

        foreach ($formats as $format) {
            try {
                $data = $format['build']();
            } catch (Throwable $e) {
                Log::warning('Wallet pass could not be attached to the mail', [
                    'application_id' => $application->getKey(),
                    'attachment' => $format['name'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $this->walletAttachments[] = [
                'name' => $format['name'],
                'mime' => $format['mime'],
                'data' => $data,
            ];

            $attachments[] = Attachment::fromData(fn (): string => $data, $format['name'])
                ->withMime($format['mime']);
        }

        return $attachments;
    }
}
