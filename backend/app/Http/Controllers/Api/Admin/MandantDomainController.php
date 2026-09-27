<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesMandantRouteParameter;
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
 *
 * The `{mandant}` route parameter is checked per call against the caller via
 * `assertMandantRouteParameter()` — a mandant's domains are tenant
 * infrastructure (they decide which mandant a request resolves to), so this
 * surface must stay as narrow as the mandant CRUD itself: super_admin from any
 * host, nobody else.
 */
class MandantDomainController extends Controller
{
    use ResolvesMandantRouteParameter;

    /**
     * DNS hostname, lowercase only, without scheme/port/path: one or more
     * labels of 1-63 chars, hyphens allowed inside a label but never leading
     * or trailing, single dot separators.
     */
    private const HOSTNAME_REGEX = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/';

    public function index(Request $request, Mandant $mandant): AnonymousResourceCollection
    {
        $this->assertMandantRouteParameter($request, $mandant);

        return MandantDomainResource::collection(
            $mandant->domains()->orderBy('id')->get(),
        );
    }

    public function store(Request $request, Mandant $mandant): JsonResponse
    {
        $this->assertMandantRouteParameter($request, $mandant);

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

        // Drop any cached MISSING sentinel for this host so the freshly added
        // domain is resolvable immediately instead of returning the negative
        // cache entry for NEGATIVE_CACHE_TTL_SECONDS.
        MandantContext::forgetHost($domain->hostname);
        // The `trustHosts` allow-list holds the full hostname list under its own
        // cache key — a new tenant domain is not allow-listed until that cache
        // is dropped, otherwise every request to it would 400.
        MandantContext::forgetHostnames();

        return (new MandantDomainResource($domain))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, Mandant $mandant, string $domain): Response
    {
        $this->assertMandantRouteParameter($request, $mandant);

        $domainModel = $mandant->domains()->findOrFail((int) $domain);

        MandantContext::forgetHost($domainModel->hostname);
        MandantContext::forgetHostnames();
        $domainModel->delete();

        return response()->noContent();
    }
}
