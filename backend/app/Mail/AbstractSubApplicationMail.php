<?php

namespace App\Mail;

use App\Models\SubApplication;
use App\Services\WalletPassService;
use App\Support\VerifyLink;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared plumbing for the P6 sub-accreditation notifications (Park-/Sitzkarte,
 * D9): approval and denial of a `SubApplication`.
 *
 * A sibling of {@see AbstractApplicationMail}, not a subclass of it: that one is
 * typed to `Application` in every method (recipient, view data, wallet passes),
 * and the sub row has no `mandant_id`, no `qr_token` and no category/event of
 * its own. The two chains that a sub-application needs are:
 *
 *   - **recipient** — `sub_application.user` (denormalised from the main
 *     application at apply time, therefore always the applicant of the main
 *     row),
 *   - **context** — `subAccreditation.accreditation.category|event|team` plus
 *     `subAccreditation.type`, because a Park-/Sitzkarte is a quota object hung
 *     on a main accreditation and describes nothing on its own.
 *
 * ## The mandant is the SUB-accreditation's mandant
 *
 * `SubAllocationService` resolves it exactly the same way
 * (`subAccreditation.accreditation.mandant_id`, its `mandantId()` helper, the
 * `Blacklist` scope of the allocation) — and that is also the relation the
 * authorisation layer uses (`SubApplication::scopeForMandant()`,
 * `resolveRouteBindingQuery()`). `sub_application.application.accreditation`
 * would answer the same question for every row the apply endpoint creates (it
 * looks the main application up on `$sub->accreditation_id`), but it is the
 * application's mandant, not the one that decided the sub-quota: for a row
 * written out of band, the notification has to go to the mandant whose decision
 * it reports. The call sites therefore pass the mandant in; this class never
 * guesses it.
 *
 * German only, like every other applicant mail here; i18n of the mail templates
 * is a documented follow-up.
 */
abstract class AbstractSubApplicationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The wallet-pass payloads that were actually attached, mirrored for
     * inspection (`Mail::fake()` never invokes `attachments()`, so the tests
     * read this after calling it) — same contract as
     * {@see AbstractApplicationMail::$walletAttachments}.
     *
     * @var list<array{name: string, mime: string, data: string}>
     */
    public array $walletAttachments = [];

    /**
     * Load the accreditation context, bind the applicant as recipient and
     * return the sub-application (fully prepared for the view).
     */
    protected function prepare(SubApplication $subApplication): SubApplication
    {
        $subApplication->loadMissing([
            'user:id,email,name',
            'application.accreditation.mandant.domains',
            'subAccreditation.accreditation.category',
            'subAccreditation.accreditation.event',
            'subAccreditation.accreditation.team',
            'subAccreditation.accreditation.mandant:id,name',
        ]);

        if ($subApplication->user !== null && $subApplication->user->email !== null) {
            $this->to($subApplication->user->email, $subApplication->user->name);
        }

        return $subApplication;
    }

    /**
     * The shared view data of every sub-accreditation notification.
     *
     * @return array<string, string|null>
     */
    protected function viewData(SubApplication $subApplication): array
    {
        $accreditation = $subApplication->subAccreditation?->accreditation;

        return [
            'userName' => $subApplication->user?->name ?? '',
            'categoryName' => $accreditation?->category?->name ?? '',
            'eventTitle' => $accreditation?->event?->title,
            'teamName' => $accreditation?->team?->name,
            'typeLabel' => $this->typeLabel($subApplication),
        ];
    }

    /**
     * The wallet-pass type of this sub-accreditation, `'park'` or `'seat'`.
     *
     * `WalletPassService::assertType()` refuses `'main'` for a
     * `SubApplication`, and anything that is not `'seat'` is a park card — the
     * same derivation `WalletController::subApple()` performs for the download,
     * and the one `WalletPassService::context()` labels with `Parkkarte` /
     * `Sitzkarte`. Duplicated in three places on purpose: a missing `type`
     * column degrades to `park`, which is what the download does too.
     */
    protected function walletType(SubApplication $subApplication): string
    {
        return $subApplication->subAccreditation?->type === 'seat' ? 'seat' : 'park';
    }

    /**
     * The German label of the sub type (`Parkkarte` / `Sitzkarte`) — the same
     * word `WalletPassService::context()` puts into the pass itself, so the mail
     * and the attached file call the same thing the same name.
     */
    protected function typeLabel(SubApplication $subApplication): string
    {
        return $this->walletType($subApplication) === 'seat' ? 'Sitzkarte' : 'Parkkarte';
    }

    /**
     * The public verify page of the sub-application's **main** application.
     *
     * A sub row has no `qr_token` of its own; the bearer of the credential is
     * the approved main application — which is exactly what the QR code inside
     * the attached sub-pass encodes (`WalletPassService::verifyUrl()` receives
     * the main application for a sub subject). The mail therefore points at the
     * same page as the pass it carries, and never at a sub-specific badge.
     *
     * Empty string (the view renders no button) when the main row is gone: a
     * sub-application without its application is the corrupt state the guards
     * everywhere else degrade over, and a mail must not fatal on it.
     */
    protected function verifyUrl(SubApplication $subApplication): string
    {
        $application = $subApplication->application;

        if ($application === null) {
            return '';
        }

        $subApplication->loadMissing('application.accreditation.mandant.domains');

        return VerifyLink::for($application);
    }

    /**
     * P6: the sub wallet passes as mail attachments — the Apple `.pkpass` and
     * the Google payload, named and typed exactly like the sub download
     * endpoint (`WalletPassService::appleFilename()` /
     * `googleFilename()` with the `park`/`seat` type, and its `*_CONTENT_TYPE`
     * constants; both are the single contract for either use).
     *
     * ## Fail-safe, by design
     *
     * Identical to {@see AbstractApplicationMail::buildWalletAttachments()} and
     * for the identical reason: the mail is the notification, the pass is a
     * convenience. Each format is built in its own `try`; a failure is logged
     * and the format is SKIPPED, never rethrown — since Position 45
     * `MandantMailerService::deliver()` lets transport errors through, so a
     * throwing pass builder would burn the whole retry budget and lose the
     * notification with it.
     *
     * Without credentials `WalletPassService` degrades deliberately (unsigned
     * `.pkpass`, preview `EventTicketObject`); those degraded files ARE
     * attached, and only `approved` rows carry a pass at all.
     *
     * @return list<Attachment>
     */
    protected function buildWalletAttachments(SubApplication $subApplication): array
    {
        $this->walletAttachments = [];

        if ($subApplication->status !== 'approved') {
            return [];
        }

        $wallet = app(WalletPassService::class);
        $type = $this->walletType($subApplication);

        /** @var list<array{name: string, mime: string, build: callable(): string}> $formats */
        $formats = [
            [
                'name' => $wallet->appleFilename($subApplication, $type),
                'mime' => WalletPassService::APPLE_CONTENT_TYPE,
                'build' => fn (): string => $wallet->buildApplePass($subApplication, $type),
            ],
            [
                'name' => $wallet->googleFilename($subApplication, $type),
                'mime' => WalletPassService::GOOGLE_CONTENT_TYPE,
                'build' => fn (): string => $wallet->buildGooglePass($subApplication, $type),
            ],
        ];

        $attachments = [];

        foreach ($formats as $format) {
            try {
                $data = $format['build']();
            } catch (Throwable $e) {
                Log::warning('Wallet pass could not be attached to the mail', [
                    'sub_application_id' => $subApplication->getKey(),
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
