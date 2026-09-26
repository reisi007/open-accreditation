<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Models\Accreditation;
use App\Models\BadgeTemplate;
use App\Services\BadgeExportService;
use App\Services\MediaHostResolver;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Badge export (P4):
 *
 *   POST /api/admin/accreditations/{accreditation}/badges/export
 *       body: {format: 'pdf'|'csv', template_id?}
 *
 * Streams the approved applications of one accreditation as a PDF (A6 cards)
 * or a CSV (DE-Excel, `;`-separated). `template_id` null → the mandant's
 * default template; without a default the export answers 422 "No badge
 * template". A foreign accreditation/template is 404; team_admin is scoped to
 * his own team's accreditations (403 otherwise). Download headers:
 * `Content-Type application/pdf` / `text/csv; charset=UTF-8` and
 * `Content-Disposition: attachment`.
 *
 * ## F2 — a domain-less mandant cannot produce verifiable badges
 *
 * Both formats embed the verify URL, and that URL's host is the mandant's own
 * domain or — without a domain — the host of `config('app.url')`. A v2 QR token
 * is TENANT-BOUND: it signs the issuing mandant id, and `VerifyController`
 * requires that claim to name the mandant the request host resolved to. So when
 * the fallback host routes to a different mandant (in production: the primary
 * Verband's host), every printed badge 404s on every scan — permanently, and
 * not repairable by `accreditation:backfill-qr-tokens`, because the token is
 * valid and only the URL around it is wrong.
 *
 * The export therefore refuses (422) instead of shipping dead badges. A mandant
 * whose app.url host is routed to nobody — the local single-box shape — is
 * unaffected; see `MediaHostResolver::ownsFallbackHost()`.
 */
class BadgeExportController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(
        private readonly BadgeExportService $exports,
        private readonly MediaHostResolver $hosts,
    ) {}

    public function export(Request $request, Accreditation $accreditation): StreamedResponse|JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $accreditation = $this->assertMandantScope($accreditation, $mandantId);
        $teamIds = $this->teamIds($request);
        $this->assertOwnership($accreditation, $teamIds);

        $this->assertVerifiableBadges();

        $validated = $request->validate([
            'format' => ['required', Rule::in(['pdf', 'csv'])],
            'template_id' => ['nullable', 'integer'],
        ]);

        $template = $this->resolveTemplate($mandantId, $validated['template_id'] ?? null);

        return $this->exports->export($accreditation, $template, (string) $validated['format']);
    }

    /**
     * The mandant must be able to mint a badge that verifies back on its own
     * host — see the class docblock (F2).
     */
    private function assertVerifiableBadges(): void
    {
        $mandant = MandantContext::current();

        // `currentMandantId()` above already guaranteed a context; this narrows
        // the type for the static analyser, it is not a second authorization.
        abort_if($mandant === null, 404, 'No mandant context for this request.');

        abort_if(
            $this->hosts->hostFor($mandant) === null && ! $this->hosts->ownsFallbackHost($mandant),
            422,
            'Mandant hat keine Domain — QR-Codes können nicht generiert werden.',
        );
    }

    private function resolveTemplate(int $mandantId, ?int $templateId): BadgeTemplate
    {
        if ($templateId !== null) {
            $template = BadgeTemplate::query()->forMandant($mandantId)->whereKey($templateId)->first();
            abort_if($template === null, 404, 'Badge template not found.');

            return $template;
        }

        $template = BadgeTemplate::query()->forMandant($mandantId)->default()->orderBy('id')->first();
        abort_if($template === null, 422, 'No badge template.');

        return $template;
    }
}
