<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Models\Accreditation;
use App\Models\BadgeTemplate;
use App\Services\BadgeExportService;
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
 */
class BadgeExportController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(private readonly BadgeExportService $exports) {}

    public function export(Request $request, Accreditation $accreditation): StreamedResponse|JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $accreditation = $this->assertMandantScope($accreditation, $mandantId);
        $teamIds = $this->teamIds($request);
        $this->assertOwnership($accreditation, $teamIds);

        $validated = $request->validate([
            'format' => ['required', Rule::in(['pdf', 'csv'])],
            'template_id' => ['nullable', 'integer'],
        ]);

        $template = $this->resolveTemplate($mandantId, $validated['template_id'] ?? null);

        return $this->exports->export($accreditation, $template, (string) $validated['format']);
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
