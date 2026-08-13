<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MandantDomainResource;
use App\Models\Mandant;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin management of the hostnames routed to a mandant. Guarded by
 * `can:mandants.manage` (super_admin-only).
 */
class MandantDomainController extends Controller
{
    /**
     * DNS hostname, lowercase only, without scheme/port/path: one or more
     * labels of 1-63 chars, hyphens allowed inside a label but never leading
     * or trailing, single dot separators.
     */
    private const HOSTNAME_REGEX = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/';

    public function index(Mandant $mandant): AnonymousResourceCollection
    {
        return MandantDomainResource::collection(
            $mandant->domains()->orderBy('id')->get(),
        );
    }

    public function store(Request $request, Mandant $mandant): JsonResponse
    {
        $validated = $request->validate([
            'hostname' => [
                'required',
                'string',
                'max:255',
                'regex:'.self::HOSTNAME_REGEX,
                Rule::unique('mandant_domains', 'hostname'),
            ],
        ]);

        $domain = $mandant->domains()->create([
            'hostname' => strtolower($validated['hostname']),
        ]);

        return (new MandantDomainResource($domain))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Mandant $mandant, string $domain): Response
    {
        $domainModel = $mandant->domains()->findOrFail((int) $domain);

        MandantContext::forgetHost($domainModel->hostname);
        $domainModel->delete();

        return response()->noContent();
    }
}
