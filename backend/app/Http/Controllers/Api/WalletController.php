<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\SubApplication;
use App\Models\User;
use App\Services\WalletPassService;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Wallet pass downloads (P6) for the authenticated applicant.
 *
 *   GET /api/applications/{application}/wallet           Apple .pkpass of an own
 *                                                        approved application
 *   GET /api/applications/{application}/wallet/google    Google payload (EventTicket
 *                                                        object JSON, or JWT when a
 *                                                        service account is configured)
 *   GET /api/sub-applications/{subApplication}/wallet    Apple .pkpass of an own
 *                                                        approved sub-application
 *                                                        (Park-/Sitzkarte)
 *
 * Ownership + mandant scope are enforced first (foreign rows → 404), then the
 * `approved` status (anything else → 422 `{message}`). Wallet build failures
 * are reported (logged) and answered with a clean 500 `{message}`.
 */
class WalletController extends Controller
{
    public function __construct(private readonly WalletPassService $wallet) {}

    public function apple(Request $request, Application $application): Response|JsonResponse
    {
        $application = $this->ownApprovedApplication($request, $application);

        return $this->appleResponse(
            fn (): string => $this->wallet->buildApplePass($application, 'main'),
            'accreditation-'.$application->id,
        );
    }

    public function google(Request $request, Application $application): Response|JsonResponse
    {
        $application = $this->ownApprovedApplication($request, $application);

        try {
            $payload = $this->wallet->buildGooglePass($application, 'main');
        } catch (Throwable $e) {
            report($e);
            abort(500, 'Could not generate the wallet pass.');
        }

        return response($payload, 200, ['Content-Type' => 'application/json']);
    }

    public function subApple(Request $request, SubApplication $subApplication): Response|JsonResponse
    {
        $subApplication = $this->ownApprovedSubApplication($request, $subApplication);
        $type = $subApplication->subAccreditation?->type === 'seat' ? 'seat' : 'park';

        return $this->appleResponse(
            fn (): string => $this->wallet->buildApplePass($subApplication, $type),
            $type.'-'.$subApplication->id,
        );
    }

    private function appleResponse(callable $build, string $filename): Response|JsonResponse
    {
        try {
            $pass = $build();
        } catch (Throwable $e) {
            report($e);
            abort(500, 'Could not generate the wallet pass.');
        }

        return response($pass, 200, [
            'Content-Type' => 'application/vnd.apple.pkpass',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pkpass"',
        ]);
    }

    private function ownApprovedApplication(Request $request, Application $application): Application
    {
        $mandant = $this->currentMandant();
        /** @var User $user */
        $user = $request->user();

        $application = Application::query()
            ->forUser($user->id)
            ->forMandant($mandant->id)
            ->with([
                'accreditation.category',
                'accreditation.event',
                'accreditation.mandant',
                'user',
            ])
            ->findOrFail($application->id);

        abort_unless($application->status === 'approved', 422, 'Only approved applications can be downloaded as a wallet pass.');

        return $application;
    }

    private function ownApprovedSubApplication(Request $request, SubApplication $subApplication): SubApplication
    {
        $mandant = $this->currentMandant();
        /** @var User $user */
        $user = $request->user();

        $subApplication = SubApplication::query()
            ->forUser($user->id)
            ->forMandant($mandant->id)
            ->with([
                'application.accreditation.category',
                'application.accreditation.event',
                'application.accreditation.mandant',
                'application.user',
                'user',
                'subAccreditation.accreditation.category',
                'subAccreditation.accreditation.event',
                'subAccreditation.accreditation.mandant',
            ])
            ->findOrFail($subApplication->id);

        abort_unless($subApplication->status === 'approved', 422, 'Only approved sub-applications can be downloaded as a wallet pass.');

        return $subApplication;
    }

    private function currentMandant(): Mandant
    {
        $mandant = MandantContext::current();
        abort_if($mandant === null, 404, 'Mandant not found');

        return $mandant;
    }
}
